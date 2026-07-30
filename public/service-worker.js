'use strict';

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const attendanceUrl = new URL(
        'attendance/me',
        self.registration.scope
    ).href;

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({
            type: 'window',
            includeUncontrolled: true,
        });

        for (const client of windows) {
            if ('focus' in client) {
                await client.focus();

                if ('navigate' in client) {
                    await client.navigate(attendanceUrl);
                }

                return;
            }
        }

        if (self.clients.openWindow) {
            await self.clients.openWindow(attendanceUrl);
        }
    })());
});
