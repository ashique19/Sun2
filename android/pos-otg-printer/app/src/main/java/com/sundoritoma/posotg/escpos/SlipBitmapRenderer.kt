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
 * Renders a shipping slip as a 1-bit-friendly bitmap for thermal width.
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
            addBlock(slip.parcelId, width * 0.12f, 16f)
        }
        addBlock(slip.brand, width * 0.08f, 6f)
        addBlock(slip.helpline, width * 0.05f, 16f)
        addBlock(slip.name, width * 0.07f, 8f)
        addBlock(slip.phone, width * 0.065f, 8f)
        addBlock(slip.address, width * 0.055f, 16f)
        addBlock("TOTAL DUE  ${"%,d".format(slip.dueTk)} Tk", width * 0.07f, 8f)

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
