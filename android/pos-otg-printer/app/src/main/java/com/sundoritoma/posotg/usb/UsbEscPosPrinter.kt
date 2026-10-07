package com.sundoritoma.posotg.usb

import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.hardware.usb.UsbConstants
import android.hardware.usb.UsbDevice
import android.hardware.usb.UsbDeviceConnection
import android.hardware.usb.UsbEndpoint
import android.hardware.usb.UsbInterface
import android.hardware.usb.UsbManager
import android.os.Build
import com.sundoritoma.posotg.data.PrintJob
import com.sundoritoma.posotg.escpos.EscPosCommands
import com.sundoritoma.posotg.escpos.RasterEncoder
import com.sundoritoma.posotg.escpos.SlipBitmapRenderer

class UsbEscPosPrinter(private val context: Context) {
    companion object {
        const val ACTION_USB_PERMISSION = "com.sundoritoma.posotg.USB_PERMISSION"

        fun deviceLabel(device: UsbDevice): String {
            val name = device.productName?.trim().orEmpty()
                .ifBlank { device.deviceName }
            return "$name  (VID=${hex(device.vendorId)} PID=${hex(device.productId)})"
        }

        private fun hex(value: Int): String = "0x" + value.toString(16).padStart(4, '0')
    }

    private val usbManager = context.getSystemService(Context.USB_SERVICE) as UsbManager
    private var device: UsbDevice? = null
    private var connection: UsbDeviceConnection? = null
    private var usbInterface: UsbInterface? = null
    private var outEndpoint: UsbEndpoint? = null

    var onStatus: ((String) -> Unit)? = null
    var onConnectionChanged: (() -> Unit)? = null

    private val permissionReceiver = object : BroadcastReceiver() {
        override fun onReceive(ctx: Context?, intent: Intent?) {
            if (intent?.action != ACTION_USB_PERMISSION) {
                return
            }
            val granted = intent.getBooleanExtra(UsbManager.EXTRA_PERMISSION_GRANTED, false)
            val dev = parcelDevice(intent)
            if (granted && dev != null) {
                open(dev)
            } else {
                onStatus?.invoke("USB permission denied. Tap Connect and allow access.")
                onConnectionChanged?.invoke()
            }
        }
    }

    private val attachReceiver = object : BroadcastReceiver() {
        override fun onReceive(ctx: Context?, intent: Intent?) {
            when (intent?.action) {
                UsbManager.ACTION_USB_DEVICE_ATTACHED -> {
                    val dev = parcelDevice(intent)
                    onStatus?.invoke(
                        if (dev != null) {
                            "USB attached: ${deviceLabel(dev)}. Tap Connect."
                        } else {
                            "USB device attached. Tap Connect."
                        },
                    )
                    onConnectionChanged?.invoke()
                }
                UsbManager.ACTION_USB_DEVICE_DETACHED -> {
                    val dev = parcelDevice(intent)
                    if (dev != null && device?.deviceId == dev.deviceId) {
                        close()
                        onStatus?.invoke("Printer disconnected.")
                    } else {
                        onStatus?.invoke("USB device removed. ${describeDeviceList()}")
                    }
                    onConnectionChanged?.invoke()
                }
            }
        }
    }

    fun register() {
        val permissionFilter = IntentFilter(ACTION_USB_PERMISSION)
        val attachFilter = IntentFilter().apply {
            addAction(UsbManager.ACTION_USB_DEVICE_ATTACHED)
            addAction(UsbManager.ACTION_USB_DEVICE_DETACHED)
        }
        if (Build.VERSION.SDK_INT >= 33) {
            // Custom permission callback stays private to this app.
            context.registerReceiver(permissionReceiver, permissionFilter, Context.RECEIVER_NOT_EXPORTED)
            // System USB attach/detach broadcasts require an exported receiver on API 33+.
            context.registerReceiver(attachReceiver, attachFilter, Context.RECEIVER_EXPORTED)
        } else {
            context.registerReceiver(permissionReceiver, permissionFilter)
            context.registerReceiver(attachReceiver, attachFilter)
        }
    }

    fun unregister() {
        try {
            context.unregisterReceiver(permissionReceiver)
        } catch (_: Exception) {
        }
        try {
            context.unregisterReceiver(attachReceiver)
        } catch (_: Exception) {
        }
        close()
    }

    fun listDevices(): List<UsbDevice> {
        return usbManager.deviceList.values
            .sortedWith(
                compareByDescending<UsbDevice> { printerScore(it) }
                    .thenBy { it.deviceName },
            )
    }

    fun describeDeviceList(): String {
        val devices = listDevices()
        if (devices.isEmpty()) {
            return "No USB devices seen. Check OTG cable, printer power, and phone OTG."
        }
        return devices.joinToString("\n") { "• ${deviceLabel(it)}" }
    }

    fun requestConnect(preferred: UsbDevice? = null) {
        val devices = listDevices()
        val candidate = preferred
            ?: devices.firstOrNull { printerScore(it) > 0 }
            ?: devices.firstOrNull()

        if (candidate == null) {
            onStatus?.invoke(
                "No USB device found.\n" +
                    "1) Use a data OTG cable/adapter\n" +
                    "2) Power the printer\n" +
                    "3) Accept the phone USB/OTG prompt\n" +
                    "4) Tap Connect again",
            )
            onConnectionChanged?.invoke()
            return
        }

        if (usbManager.hasPermission(candidate)) {
            open(candidate)
            return
        }

        // Android 14+ silently skips the permission dialog unless the PendingIntent
        // targets this package explicitly and is mutable.
        val intent = Intent(ACTION_USB_PERMISSION).apply {
            setPackage(context.packageName)
        }
        val flags = PendingIntent.FLAG_UPDATE_CURRENT or
            if (Build.VERSION.SDK_INT >= 31) {
                PendingIntent.FLAG_MUTABLE
            } else {
                0
            }
        val pi = PendingIntent.getBroadcast(context, candidate.deviceId, intent, flags)
        onStatus?.invoke("Allow USB access for:\n${deviceLabel(candidate)}")
        usbManager.requestPermission(candidate, pi)
    }

    fun isConnected(): Boolean = connection != null && outEndpoint != null

    fun connectedLabel(): String {
        val d = device ?: return "Not connected"
        return "Connected: ${deviceLabel(d)}"
    }

    fun printJob(job: PrintJob, paperDots: Int) {
        val conn = connection
        val ep = outEndpoint
        if (conn == null || ep == null) {
            throw IllegalStateException("Printer not connected")
        }

        write(conn, ep, EscPosCommands.INIT)
        job.slips.forEachIndexed { index, slip ->
            onStatus?.invoke("Printing ${index + 1}/${job.slips.size}…")
            write(conn, ep, EscPosCommands.ALIGN_CENTER)
            val bitmap = SlipBitmapRenderer.render(slip, paperDots)
            write(conn, ep, RasterEncoder.encode(bitmap))
            write(conn, ep, EscPosCommands.feed(3))
            if (job.cutAfterEach) {
                write(conn, ep, EscPosCommands.CUT)
            }
            Thread.sleep(250)
        }
        if (!job.cutAfterEach) {
            write(conn, ep, EscPosCommands.CUT)
        }
        onStatus?.invoke("Printed ${job.slips.size} slip(s). ${connectedLabel()}")
    }

    private fun open(dev: UsbDevice) {
        close()
        val iface = findPrinterInterface(dev)
            ?: run {
                onStatus?.invoke(
                    "No printable USB interface on ${deviceLabel(dev)}.\n" +
                        "Interfaces: ${dev.interfaceCount}. Try another cable/port.",
                )
                onConnectionChanged?.invoke()
                return
            }
        val endpoint = findOutEndpoint(iface)
            ?: run {
                onStatus?.invoke("No USB OUT endpoint on ${deviceLabel(dev)}")
                onConnectionChanged?.invoke()
                return
            }
        val conn = usbManager.openDevice(dev)
        if (conn == null) {
            onStatus?.invoke("Could not open ${deviceLabel(dev)}. Re-plug and grant permission.")
            onConnectionChanged?.invoke()
            return
        }
        if (!conn.claimInterface(iface, true)) {
            conn.close()
            onStatus?.invoke("Could not claim USB interface on ${deviceLabel(dev)}")
            onConnectionChanged?.invoke()
            return
        }
        device = dev
        connection = conn
        usbInterface = iface
        outEndpoint = endpoint
        onStatus?.invoke(connectedLabel())
        onConnectionChanged?.invoke()
    }

    fun close() {
        try {
            usbInterface?.let { connection?.releaseInterface(it) }
        } catch (_: Exception) {
        }
        try {
            connection?.close()
        } catch (_: Exception) {
        }
        device = null
        connection = null
        usbInterface = null
        outEndpoint = null
    }

    private fun printerScore(device: UsbDevice): Int {
        var score = 0
        if (hasBulkOrInterruptOut(device)) {
            score += 10
        }
        for (i in 0 until device.interfaceCount) {
            val iface = device.getInterface(i)
            if (iface.interfaceClass == UsbConstants.USB_CLASS_PRINTER) {
                score += 50
            }
            // Vendor-class bulk printers (common ESC/POS clones).
            if (iface.interfaceClass == UsbConstants.USB_CLASS_VENDOR_SPEC && hasBulkOrInterruptOut(device)) {
                score += 20
            }
        }
        // Common thermal / ESC-POS vendor IDs.
        when (device.vendorId) {
            0x04b8, // Epson
            0x0519, // Star
            0x0416, // Winbond / many Chinese POS
            0x0483, // STMicro (some clones)
            0x0525,
            0x154f,
            0x28e9,
            0x1a86, // QinHeng CH340 serial bridges
            0x067b, // Prolific
            0x0557,
            0x6868,
            0x0fe6,
            0x1155,
            0x1504,
            -> score += 30
        }
        return score
    }

    private fun hasBulkOrInterruptOut(device: UsbDevice): Boolean {
        for (i in 0 until device.interfaceCount) {
            if (findOutEndpoint(device.getInterface(i)) != null) {
                return true
            }
        }
        return false
    }

    private fun findPrinterInterface(device: UsbDevice): UsbInterface? {
        var fallback: UsbInterface? = null
        for (i in 0 until device.interfaceCount) {
            val iface = device.getInterface(i)
            val out = findOutEndpoint(iface) ?: continue
            if (iface.interfaceClass == UsbConstants.USB_CLASS_PRINTER) {
                return iface
            }
            if (out.type == UsbConstants.USB_ENDPOINT_XFER_BULK) {
                return iface
            }
            if (fallback == null) {
                fallback = iface
            }
        }
        return fallback ?: if (device.interfaceCount > 0) device.getInterface(0) else null
    }

    private fun findOutEndpoint(iface: UsbInterface): UsbEndpoint? {
        var interruptOut: UsbEndpoint? = null
        for (e in 0 until iface.endpointCount) {
            val ep = iface.getEndpoint(e)
            if (ep.direction != UsbConstants.USB_DIR_OUT) {
                continue
            }
            if (ep.type == UsbConstants.USB_ENDPOINT_XFER_BULK) {
                return ep
            }
            if (ep.type == UsbConstants.USB_ENDPOINT_XFER_INT && interruptOut == null) {
                interruptOut = ep
            }
        }
        return interruptOut
    }

    private fun write(conn: UsbDeviceConnection, ep: UsbEndpoint, data: ByteArray) {
        var offset = 0
        while (offset < data.size) {
            val chunk = minOf(512, data.size - offset)
            val sent = conn.bulkTransfer(ep, data, offset, chunk, 5_000)
            if (sent < 0) {
                throw IllegalStateException("USB write failed at offset $offset")
            }
            offset += sent
        }
    }

    private fun parcelDevice(intent: Intent): UsbDevice? {
        return if (Build.VERSION.SDK_INT >= 33) {
            intent.getParcelableExtra(UsbManager.EXTRA_DEVICE, UsbDevice::class.java)
        } else {
            @Suppress("DEPRECATION")
            intent.getParcelableExtra(UsbManager.EXTRA_DEVICE)
        }
    }
}
