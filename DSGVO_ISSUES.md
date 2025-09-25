# DSGVO/GDPR Compliance Issues in Pagekit

## Critical Issues Found

### 1. Dashboard Location Widget - Google Maps API
**File**: `app/system/modules/dashboard/app/components/widget-location.vue`
**Lines**: 240, 252

The Location widget makes unauthorized calls to Google Maps API:
- `https://maps.googleapis.com/maps/api/timezone/json`

**GDPR Violation**: 
- Sends user location data to Google without consent
- No privacy notice shown
- No opt-in mechanism

**Recommendation**:
1. Add consent banner before loading widget
2. Use OpenStreetMap/Nominatim as alternative
3. Make Google Maps optional with explicit consent
4. Add privacy settings in admin panel

### 2. Weather Widget - OpenWeatherMap API
**File**: `app/system/modules/dashboard/src/Controller/DashboardController.php`
**Line**: 15

Uses OpenWeatherMap API which may track requests.

**Recommendation**:
- Add API key configuration
- Show privacy notice
- Cache results to minimize API calls