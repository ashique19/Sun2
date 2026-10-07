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
    }

    private val usbManager = context.getSystemService(Context.USB_SERVICE) as UsbManager
    private var device: UsbDevice? = null
    private var connection: UsbDeviceConnection? = null
    private var usbInterface: UsbInterface? = null
    private var outEndpoint: UsbEndpoint? = null

    var onStatus: ((String) -> Unit)? = null

    private val permissionReceiver = object : BroadcastReceiver() {
        override fun onReceive(ctx: Context?, intent: Intent?) {
            if (intent?.action != ACTION_USB_PERMISSION) {
                return
            }
            val granted = intent.getBooleanExtra(UsbManager.EXTRA_PERMISSION_GRANTED, false)
            val dev = if (Build.VERSION.SDK_INT >= 33) {
                intent.getParcelableExtra(UsbManager.EXTRA_DEVICE, UsbDevice::class.java)
            } else {
                @Suppress("DEPRECATION")
                intent.getParcelableExtra(UsbManager.EXTRA_DEVICE)
            }
            if (granted && dev != null) {
                open(dev)
            } else {
                onStatus?.invoke("USB permission denied")
            }
        }
    }

    fun register() {
        val filter = IntentFilter(ACTION_USB_PERMISSION)
        if (Build.VERSION.SDK_INT >= 33) {
            context.registerReceiver(permissionReceiver, filter, Context.RECEIVER_NOT_EXPORTED)
        } else {
            context.registerReceiver(permissionReceiver, filter)
        }
    }

    fun unregister() {
        try {
            context.unregisterReceiver(permissionReceiver)
        } catch (_: Exception) {
        }
        close()
    }

    fun listDevices(): List<UsbDevice> = usbManager.deviceList.values.toList()

    fun requestConnect(preferred: UsbDevice? = null) {
        val candidate = preferred ?: listDevices().firstOrNull()
        if (candidate == null) {
            onStatus?.invoke("No USB device found. Use an OTG cable and power the printer.")
            return
        }
        if (usbManager.hasPermission(candidate)) {
            open(candidate)
            return
        }
        val flags = PendingIntent.FLAG_UPDATE_CURRENT or
            if (Build.VERSION.SDK_INT >= 31) PendingIntent.FLAG_MUTABLE else 0
        val pi = PendingIntent.getBroadcast(context, 0, Intent(ACTION_USB_PERMISSION), flags)
        onStatus?.invoke("Requesting USB permission for ${candidate.deviceName}…")
        usbManager.requestPermission(candidate, pi)
    }

    fun isConnected(): Boolean = connection != null && outEndpoint != null

    fun connectedLabel(): String {
        val d = device ?: return "Not connected"
        return "Connected: vid=${d.vendorId} pid=${d.productId}"
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
                onStatus?.invoke("No printer interface on ${dev.deviceName}")
                return
            }
        val endpoint = findOutEndpoint(iface)
            ?: run {
                onStatus?.invoke("No OUT endpoint on ${dev.deviceName}")
                return
            }
        val conn = usbManager.openDevice(dev)
        if (conn == null) {
            onStatus?.invoke("Could not open USB device")
            return
        }
        if (!conn.claimInterface(iface, true)) {
            conn.close()
            onStatus?.invoke("Could not claim USB interface")
            return
        }
        device = dev
        connection = conn
        usbInterface = iface
        outEndpoint = endpoint
        onStatus?.invoke(connectedLabel())
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

    private fun findPrinterInterface(device: UsbDevice): UsbInterface? {
        for (i in 0 until device.interfaceCount) {
            val iface = device.getInterface(i)
            for (e in 0 until iface.endpointCount) {
                val ep = iface.getEndpoint(e)
                if (ep.direction == UsbConstants.USB_DIR_OUT &&
                    (ep.type == UsbConstants.USB_ENDPOINT_XFER_BULK ||
                        ep.type == UsbConstants.USB_ENDPOINT_XFER_INT)
                ) {
                    return iface
                }
            }
        }
        return if (device.interfaceCount > 0) device.getInterface(0) else null
    }

    private fun findOutEndpoint(iface: UsbInterface): UsbEndpoint? {
        for (e in 0 until iface.endpointCount) {
            val ep = iface.getEndpoint(e)
            if (ep.direction == UsbConstants.USB_DIR_OUT &&
                (ep.type == UsbConstants.USB_ENDPOINT_XFER_BULK ||
                    ep.type == UsbConstants.USB_ENDPOINT_XFER_INT)
            ) {
                return ep
            }
        }
        return null
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
}
