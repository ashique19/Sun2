package com.sundoritoma.posotg.escpos

object EscPosCommands {
    val INIT = byteArrayOf(0x1B, 0x40)
    val ALIGN_CENTER = byteArrayOf(0x1B, 0x61, 0x01)
    val ALIGN_LEFT = byteArrayOf(0x1B, 0x61, 0x00)
    val FEED_LINES = byteArrayOf(0x1B, 0x64, 0x03)
    /** Partial cut — common on USB ESC/POS cutters. */
    val CUT = byteArrayOf(0x1D, 0x56, 0x01)

    fun feed(lines: Int): ByteArray = byteArrayOf(0x1B, 0x64, lines.coerceIn(0, 10).toByte())
}
