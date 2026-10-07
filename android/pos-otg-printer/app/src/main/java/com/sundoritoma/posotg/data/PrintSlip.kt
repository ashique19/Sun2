package com.sundoritoma.posotg.data

data class PrintSlip(
    val id: Int,
    val orderNumber: String,
    val parcelId: String?,
    val brand: String,
    val helpline: String,
    val name: String,
    val phone: String,
    val address: String,
    val dueTk: Int,
)

data class PrintJob(
    val slips: List<PrintSlip>,
    val cutAfterEach: Boolean = true,
)
