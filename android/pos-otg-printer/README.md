# Sundoritoma POS OTG printer

Android companion app that prints admin shipping slips on a **USB thermal POS printer** over **OTG**, with a **cut after each slip**.

Browser “Print” often scales HTML as if it were A4 onto the roll. This app sends ESC/POS **raster** images (so Bangla names work) and issues a partial cut after every invoice.

## Requirements

- Android phone/tablet with USB OTG
- ESC/POS USB thermal printer (58mm or 80mm)
- OTG adapter / cable; printer powered if required
- Sundoritoma admin site reachable from the phone (same Wi‑Fi / public URL)

## Build & install

```bash
# Needs JDK 17 + Android SDK (platforms;android-35, build-tools)
printf 'sdk.dir=%s\n' "$ANDROID_HOME" > local.properties
./gradlew :app:assembleDebug
adb install -r app/build/outputs/apk/debug/app-debug.apk
```

Or open `android/pos-otg-printer` in **Android Studio** (Ladybug+ / AGP 8.7) and Run.

Debug APK output: `app/build/outputs/apk/debug/app-debug.apk`  
Committed debug APK: [`dist/sundoritoma-pos-otg-debug.apk`](dist/sundoritoma-pos-otg-debug.apk)

## How staff print

1. In admin, select orders → **Print selected** (or open a single order print page).
2. Tap **Print via OTG app** (deep link `sundoritoma://print?slips_url=…`).
3. In the app: allow USB permission → **Connect USB printer** → **Print slips**.

The `slips_url` is a **30‑minute signed** Laravel URL (`GET /print-slips`) — no login cookie needed on the phone.

You can also paste the signed URL into the app manually.

## Slip layout

Same minimal layout as admin **Print selected**:

```
PARCEL ID
<parcel id>

Sundoritoma.com
<customer name>
```

## Paper width

- **80mm** → 576 dots (default)
- **58mm** → 384 dots

## Notes

- First USB attach may prompt for permission; accept and optionally “always”.
- The app lists every USB device it can see. Use **Refresh USB devices**, then **Connect**.
- If Connect does nothing / no permission dialog (especially Android 14+), reinstall this build — permission intents must target the app package.
- If the list is empty: try another **data** OTG cable/adapter, power the printer, and accept any phone OTG prompt.
- Cut command is ESC/POS `GS V 1` (partial cut). Full-cut printers usually still cut.
