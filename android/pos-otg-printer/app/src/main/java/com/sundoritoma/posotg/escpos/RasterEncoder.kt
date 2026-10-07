package com.sundoritoma.posotg.escpos

import android.graphics.Bitmap
import android.graphics.Color
import java.io.ByteArrayOutputStream

/**
 * ESC/POS raster bit image (GS v 0) — works for Bangla text rendered as bitmap.
 */
object RasterEncoder {
    fun encode(bitmap: Bitmap): ByteArray {
        val width = bitmap.width
        val height = bitmap.height
        val bytesPerRow = (width + 7) / 8
        val out = ByteArrayOutputStream()

        // GS v 0 m xL xH yL yH d1...dk
        out.write(byteArrayOf(0x1D, 0x76, 0x30, 0x00))
        out.write(bytesPerRow and 0xFF)
        out.write((bytesPerRow shr 8) and 0xFF)
        out.write(height and 0xFF)
        out.write((height shr 8) and 0xFF)

        val row = ByteArray(bytesPerRow)
        for (y in 0 until height) {
            row.fill(0)
            for (x in 0 until width) {
                val pixel = bitmap.getPixel(x, y)
                val lum = (Color.red(pixel) + Color.green(pixel) + Color.blue(pixel)) / 3
                if (lum < 180) {
                    val byteIndex = x / 8
                    val bit = 7 - (x % 8)
                    row[byteIndex] = (row[byteIndex].toInt() or (1 shl bit)).toByte()
                }
            }
            out.write(row)
        }

        return out.toByteArray()
    }
}
