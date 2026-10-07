package com.sundoritoma.posotg.escpos

import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.graphics.Typeface
import android.text.Layout
import android.text.StaticLayout
import android.text.TextPaint
import com.sundoritoma.posotg.data.PrintSlip

/**
 * Minimal shipping slip (matches admin print-selected):
 * PARCEL ID / id / Sundoritoma.com / customer name.
 * 80mm ≈ 576 dots; 58mm ≈ 384 dots at 203dpi.
 */
object SlipBitmapRenderer {
    fun render(slip: PrintSlip, paperDots: Int): Bitmap {
        val width = paperDots.coerceIn(384, 576)
        val padding = (width * 0.04f).toInt()
        val contentWidth = width - padding * 2

        val paint = TextPaint(Paint.ANTI_ALIAS_FLAG).apply {
            color = Color.BLACK
            isFakeBoldText = true
            typeface = Typeface.create(Typeface.SANS_SERIF, Typeface.BOLD)
        }

        val blocks = mutableListOf<Pair<StaticLayout, Float>>()
        var totalHeight = padding.toFloat()

        fun addBlock(text: String, sizeSp: Float, spacingAfter: Float = 10f) {
            if (text.isBlank()) {
                return
            }
            paint.textSize = sizeSp
            val layout = StaticLayout.Builder
                .obtain(text, 0, text.length, paint, contentWidth)
                .setAlignment(Layout.Alignment.ALIGN_CENTER)
                .setLineSpacing(0f, 1.05f)
                .setIncludePad(false)
                .build()
            blocks.add(layout to spacingAfter)
            totalHeight += layout.height + spacingAfter
        }

        if (!slip.parcelId.isNullOrBlank()) {
            addBlock("PARCEL ID", width * 0.055f, 4f)
            addBlock(slip.parcelId, width * 0.14f, 22f)
        }
        addBlock(slip.brand.ifBlank { "Sundoritoma.com" }, width * 0.09f, 18f)
        addBlock(slip.name, width * 0.09f, 8f)

        totalHeight += padding + 8

        val bitmap = Bitmap.createBitmap(width, totalHeight.toInt().coerceAtLeast(100), Bitmap.Config.ARGB_8888)
        val canvas = Canvas(bitmap)
        canvas.drawColor(Color.WHITE)

        var y = padding.toFloat()
        for ((layout, spacing) in blocks) {
            canvas.save()
            canvas.translate(padding.toFloat(), y)
            layout.draw(canvas)
            canvas.restore()
            y += layout.height + spacing
        }

        return bitmap
    }
}
