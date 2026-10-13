package com.sundoritoma.posotg.data

data class PrintSlip(
    val id: Int,
    val orderNumber: String,
    val parcelId: String?,
    val brand: String,
    val name: String,
)

data class PrintJob(
    val slips: List<PrintSlip>,
    val cutAfterEach: Boolean = true,
)
