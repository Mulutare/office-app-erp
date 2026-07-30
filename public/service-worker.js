'use strict';

self.addEventListener('push', (event) => {
    let payload = {};

    try {
        payload = event.data === null
            ? {}
            : event.data.json();
    } catch (error) {
        payload = {
            title: 'OfficeApp attendance reminder',
            body: event.data === null
                ? ''
                : event.data.text(),
        };
    }

    const title = typeof payload.title === 'string'
        && payload.title !== ''
            ? payload.title
            : 'OfficeApp attendance reminder';
    const options = {
        body: typeof payload.body === 'string'
            ? payload.body
            : '',
        tag: typeof payload.tag === 'string'
            ? payload.tag
            : 'attendance:reminder',
        data: {
            url: typeof payload.url === 'string'
                ? payload.url
                : null,
        },
        icon: new URL(
            'assets/images/company-logo.png',
            self.registration.scope
        ).href,
        badge: new URL(
            'assets/images/company-logo.png',
            self.registration.scope
        ).href,
    };

    event.waitUntil(
        self.registration.showNotification(
            title,
            options
        )
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const requestedUrl =
        event.notification.data?.url;
    const attendanceUrl =
        typeof requestedUrl === 'string'
        && requestedUrl !== ''
            ? new URL(
                requestedUrl,
                self.registration.scope
            ).href
            : new URL(
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
