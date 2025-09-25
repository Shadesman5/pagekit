# DSGVO/GDPR Compliance Issues in Pagekit

⚠️ **HINWEIS**: Diese Issues betreffen nur die Production-Version (ab Phase 4).
Für Developer/Beta-Versionen (Phase 1-3) sind diese nicht kritisch.

## Issues für Production Release (Phase 4)

### 1. Dashboard Location Widget - Google Maps API
**File**: `app/system/modules/dashboard/app/components/widget-location.vue`
**Lines**: 240, 252

The Location widget makes unauthorized calls to Google Maps API:
- `https://maps.googleapis.com/maps/api/timezone/json`

**GDPR Violation**: 
- Sends user location data to Google without consent
- No privacy notice shown
- No opt-in mechanism

**Geplante Lösung (Phase 3/4)**:
1. Cookie Consent Banner Integration
2. Google Maps Extension mit Consent-Management
3. Alternative: OpenStreetMap/Nominatim als Fallback
4. Privacy Settings im Admin Panel

**Für Developer (Phase 1-3)**:
- ℹ️ Widget zeigt Warnung im Admin Dashboard
- ℹ️ Dokumentation für Developer
- ℹ️ Kein Problem da nur interne Nutzung

### 2. Weather Widget - OpenWeatherMap API
**File**: `app/system/modules/dashboard/src/Controller/DashboardController.php`
**Line**: 15

Uses OpenWeatherMap API which may track requests.

**Recommendation**:
- Add API key configuration
- Show privacy notice
- Cache results to minimize API calls