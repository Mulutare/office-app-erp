(() => {
    'use strict';

    window.initBranchGeofenceMap = () => {
        const container = document.getElementById('branch-geofence-map');
        const latitude = document.getElementById('attendance-latitude');
        const longitude = document.getElementById('attendance-longitude');
        const radius = document.getElementById('attendance-radius');

        if (
            !container
            || !(latitude instanceof HTMLInputElement)
            || !(longitude instanceof HTMLInputElement)
            || !(radius instanceof HTMLInputElement)
            || typeof window.L === 'undefined'
        ) {
            return;
        }

        const parsedLatitude = Number(latitude.value);
        const parsedLongitude = Number(longitude.value);

        const hasCoordinates =
            latitude.value !== ''
            && longitude.value !== ''
            && Number.isFinite(parsedLatitude)
            && Number.isFinite(parsedLongitude)
            && parsedLatitude >= -90
            && parsedLatitude <= 90
            && parsedLongitude >= -180
            && parsedLongitude <= 180;

        const defaultCenter = [9.0300, 38.7400];

        const initialPosition = hasCoordinates
            ? [parsedLatitude, parsedLongitude]
            : defaultCenter;

        const map = L.map(container).setView(
            initialPosition,
            hasCoordinates ? 17 : 12
        );

        L.tileLayer(
            'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
            {
                maxZoom: 19,
                attribution:
                    '&copy; OpenStreetMap contributors'
            }
        ).addTo(map);

        const marker = L.marker(
            initialPosition,
            {
                draggable: true
            }
        ).addTo(map);

        let radiusCircle = null;

        const radiusMeters = () => {
            const value = Number(radius.value);

            return Number.isFinite(value) && value >= 10
                ? value
                : 0;
        };

        const redrawRadius = (position) => {
            const meters = radiusMeters();

            if (radiusCircle !== null) {
                map.removeLayer(radiusCircle);
                radiusCircle = null;
            }

            if (meters > 0) {
                radiusCircle = L.circle(
                    position,
                    {
                        radius: meters
                    }
                ).addTo(map);
            }
        };

        const applyPosition = (position, pan = false) => {
            latitude.value = Number(position.lat).toFixed(7);
            longitude.value = Number(position.lng).toFixed(7);

            marker.setLatLng(position);
            redrawRadius(position);

            if (pan) {
                map.panTo(position);
            }
        };

        map.on('click', (event) => {
            applyPosition(event.latlng);
        });

        marker.on('dragend', () => {
            applyPosition(marker.getLatLng());
        });

        const syncFromFields = () => {
            const lat = Number(latitude.value);
            const lng = Number(longitude.value);

            if (
                !Number.isFinite(lat)
                || !Number.isFinite(lng)
                || lat < -90
                || lat > 90
                || lng < -180
                || lng > 180
            ) {
                return;
            }

            applyPosition(
                {
                    lat,
                    lng
                },
                true
            );
        };

        latitude.addEventListener('change', syncFromFields);
        longitude.addEventListener('change', syncFromFields);

        radius.addEventListener('input', () => {
            redrawRadius(marker.getLatLng());
        });

        if (hasCoordinates) {
            redrawRadius(marker.getLatLng());
        } else if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    if (
                        latitude.value !== ''
                        || longitude.value !== ''
                    ) {
                        return;
                    }

                    const currentPosition = {
                        lat: position.coords.latitude,
                        lng: position.coords.longitude
                    };

                    marker.setLatLng(currentPosition);
                    map.setView(currentPosition, 16);
                    redrawRadius(currentPosition);
                },
                () => {
                    // Keep the default map center when location is unavailable.
                },
                {
                    enableHighAccuracy: true,
                    timeout: 8000,
                    maximumAge: 60000
                }
            );
        }

        setTimeout(() => {
            map.invalidateSize();
        }, 0);
    };

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            window.initBranchGeofenceMap
        );
    } else {
        window.initBranchGeofenceMap();
    }
})();