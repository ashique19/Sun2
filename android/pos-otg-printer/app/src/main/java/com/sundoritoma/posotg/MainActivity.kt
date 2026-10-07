package com.sundoritoma.posotg

import android.content.Intent
import android.os.Bundle
import android.widget.Toast
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.sundoritoma.posotg.data.PrintJob
import com.sundoritoma.posotg.data.SlipRepository
import com.sundoritoma.posotg.databinding.ActivityMainBinding
import com.sundoritoma.posotg.usb.UsbEscPosPrinter
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

class MainActivity : AppCompatActivity() {
    private lateinit var binding: ActivityMainBinding
    private lateinit var printer: UsbEscPosPrinter
    private val repository = SlipRepository()
    private var job: PrintJob? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        printer = UsbEscPosPrinter(this)
        printer.onStatus = { message ->
            runOnUiThread {
                binding.statusText.text = message
            }
        }
        printer.onConnectionChanged = {
            runOnUiThread { refreshUi() }
        }
        printer.register()

        binding.connectButton.setOnClickListener {
            showConnectChooser()
        }

        binding.refreshDevicesButton.setOnClickListener {
            refreshDeviceListStatus(toastIfEmpty = true)
        }

        binding.loadUrlButton.setOnClickListener {
            val url = binding.slipsUrlInput.text?.toString()?.trim().orEmpty()
            if (url.isBlank()) {
                toast("Paste a signed slips URL first")
                return@setOnClickListener
            }
            loadSlips(url)
        }

        binding.printButton.setOnClickListener {
            val current = job
            if (current == null) {
                toast("Load slips first")
                return@setOnClickListener
            }
            if (!printer.isConnected()) {
                toast("Connect the USB printer first")
                showConnectChooser()
                return@setOnClickListener
            }
            printJob(current)
        }

        refreshDeviceListStatus(toastIfEmpty = false)
        handleIntent(intent)
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        handleIntent(intent)
    }

    override fun onDestroy() {
        printer.unregister()
        super.onDestroy()
    }

    private fun handleIntent(intent: Intent?) {
        val url = repository.slipsUrlFromDeepLink(intent?.data)
            ?: intent?.getStringExtra(EXTRA_SLIPS_URL)
        if (!url.isNullOrBlank()) {
            binding.slipsUrlInput.setText(url)
            loadSlips(url)
        }
    }

    private fun showConnectChooser() {
        val devices = printer.listDevices()
        if (devices.isEmpty()) {
            refreshDeviceListStatus(toastIfEmpty = true)
            return
        }

        if (devices.size == 1) {
            toast("Connecting…")
            printer.requestConnect(devices[0])
            return
        }

        val labels = devices.map { UsbEscPosPrinter.deviceLabel(it) }.toTypedArray()
        AlertDialog.Builder(this)
            .setTitle("Select USB printer")
            .setItems(labels) { _, which ->
                toast("Connecting…")
                printer.requestConnect(devices[which])
            }
            .setNegativeButton("Cancel", null)
            .show()
    }

    private fun refreshDeviceListStatus(toastIfEmpty: Boolean) {
        if (printer.isConnected()) {
            binding.statusText.text = printer.connectedLabel()
            refreshUi()
            return
        }
        val summary = printer.describeDeviceList()
        binding.statusText.text = summary
        binding.deviceListText.text = summary
        if (toastIfEmpty && printer.listDevices().isEmpty()) {
            toast("No USB devices found")
        }
        refreshUi()
    }

    private fun refreshUi() {
        binding.printButton.isEnabled = printer.isConnected() && job != null
        binding.deviceListText.text = if (printer.isConnected()) {
            printer.connectedLabel()
        } else {
            printer.describeDeviceList()
        }
    }

    private fun loadSlips(url: String) {
        binding.statusText.text = "Loading slips…"
        lifecycleScope.launch {
            try {
                val loaded = withContext(Dispatchers.IO) { repository.fetchJob(url) }
                job = loaded
                binding.slipSummary.text = loaded.slips.joinToString("\n") { slip ->
                    "#${slip.orderNumber} ${slip.parcelId ?: "-"} ${slip.name} ৳${slip.dueTk}"
                }
                binding.statusText.text = "Loaded ${loaded.slips.size} slip(s). Connect printer, then Print."
                refreshUi()
                if (!printer.isConnected()) {
                    showConnectChooser()
                }
            } catch (e: Exception) {
                job = null
                binding.printButton.isEnabled = false
                binding.statusText.text = "Load failed: ${e.message}"
            }
        }
    }

    private fun printJob(current: PrintJob) {
        val paperDots = if (binding.paper58.isChecked) 384 else 576
        binding.printButton.isEnabled = false
        lifecycleScope.launch {
            try {
                withContext(Dispatchers.IO) {
                    printer.printJob(current, paperDots)
                }
                toast("Done")
            } catch (e: Exception) {
                binding.statusText.text = "Print failed: ${e.message}"
                toast(e.message ?: "Print failed")
            } finally {
                refreshUi()
            }
        }
    }

    private fun toast(message: String) {
        Toast.makeText(this, message, Toast.LENGTH_SHORT).show()
    }

    companion object {
        const val EXTRA_SLIPS_URL = "slips_url"
    }
}
