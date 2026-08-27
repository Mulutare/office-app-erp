(() => {
    'use strict';

    const form = document.querySelector('[data-attendance-scan-form]');
    if (!(form instanceof HTMLFormElement)) return;

    form.addEventListener('submit', (event) => {
        if (form.dataset.locationReady === '1') return;
        if (form.dataset.geofenceRequired !== '1') return;

        event.preventDefault();
        const button = form.querySelector('button[type="submit"]');
        const status = form.querySelector('[data-attendance-location-status]');
        if (button instanceof HTMLButtonElement) button.disabled = true;
        if (status) status.textContent = 'Obtaining your current device location…';

        if (!navigator.geolocation) {
            if (status) status.textContent = 'This browser cannot provide a location. Use a location-capable browser or contact HR.';
            if (button instanceof HTMLButtonElement) button.disabled = false;
            return;
        }

        navigator.geolocation.getCurrentPosition((position) => {
            const fields = {
                latitude: position.coords.latitude,
                longitude: position.coords.longitude,
                accuracy: position.coords.accuracy,
            };
            Object.entries(fields).forEach(([name, value]) => {
                const input = form.elements.namedItem(name);
                if (input instanceof HTMLInputElement) input.value = String(value);
            });
            form.dataset.locationReady = '1';
            if (status) status.textContent = 'Location obtained. Verifying with the server…';
            form.requestSubmit();
        }, (error) => {
            const messages = {
                1: 'Location permission was denied. Allow location access to record attendance.',
                2: 'Your current location is unavailable. Check device location services and try again.',
                3: 'Location lookup timed out. Move to an open area and try again.',
            };
            if (status) status.textContent = messages[error.code] || 'Your location could not be obtained. Try again.';
            if (button instanceof HTMLButtonElement) button.disabled = false;
        }, {
            enableHighAccuracy: true,
            timeout: 15000,
            maximumAge: 0,
        });
    });
})();
