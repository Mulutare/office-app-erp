'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const button = document.querySelector(
        '[data-sidebar-toggle]'
    );

    const sidebar = document.querySelector(
        '[data-sidebar]'
    );

    if (button && sidebar) {
        button.addEventListener('click', () => {
            const isOpen = sidebar.classList.toggle(
                'is-open'
            );

            button.setAttribute(
                'aria-expanded',
                String(isOpen)
            );
        });
    }

    document
        .querySelectorAll('[data-password-toggle]')
        .forEach((toggle) => {
            toggle.addEventListener('click', () => {
                const inputId = toggle.getAttribute(
                    'data-password-toggle'
                );
                const input = inputId
                    ? document.getElementById(inputId)
                    : null;

                if (
                    !(input instanceof HTMLInputElement)
                ) {
                    return;
                }

                const show =
                    input.type === 'password';

                input.type = show
                    ? 'text'
                    : 'password';
                toggle.textContent = show
                    ? 'Hide'
                    : 'Show';
                toggle.setAttribute(
                    'aria-pressed',
                    String(show)
                );
                toggle.setAttribute(
                    'aria-label',
                    show
                        ? 'Hide password'
                        : 'Show password'
                );
            });
        });

    document
        .querySelectorAll('[data-leave-workflow-form]')
        .forEach((form) => {
            const workflow = form.querySelector(
                '[data-leave-workflow-select]'
            );
            const field = form.querySelector(
                '[data-leave-hr-approver-field]'
            );
            const approver = form.querySelector(
                '[data-leave-hr-approver-select]'
            );

            if (
                !(workflow instanceof HTMLSelectElement)
                || !(field instanceof HTMLElement)
                || !(approver instanceof HTMLSelectElement)
            ) {
                return;
            }

            const synchronize = () => {
                const needsHr = [
                    'hr',
                    'manager_then_hr',
                ].includes(workflow.value);

                field.hidden = !needsHr;
                approver.required = needsHr;
            };

            workflow.addEventListener(
                'change',
                synchronize
            );
            synchronize();
        });

    const approvalPreview = document.querySelector(
        '[data-leave-approval-preview]'
    );
    const leaveType = document.querySelector(
        '[data-leave-type-select]'
    );
    const leaveEmployee = document.querySelector(
        '[data-leave-employee-select]'
    );

    if (
        approvalPreview instanceof HTMLElement
        && leaveType instanceof HTMLSelectElement
    ) {
        const routeLabel = approvalPreview.querySelector(
            '[data-leave-route-label]'
        );
        const managerLabel = approvalPreview.querySelector(
            '[data-leave-manager-approver]'
        );
        const hrLabel = approvalPreview.querySelector(
            '[data-leave-hr-approver]'
        );
        const routeHelp = approvalPreview.querySelector(
            '[data-leave-route-help]'
        );
        const workflowLabels = {
            none: 'Automatically approved',
            manager: 'Manager approval',
            hr: 'HR approval',
            manager_then_hr:
                'Manager approval, then HR approval',
        };

        const synchronize = () => {
            const typeOption =
                leaveType.selectedOptions[0];
            const employeeOption =
                leaveEmployee instanceof HTMLSelectElement
                    ? leaveEmployee.selectedOptions[0]
                    : null;
            const workflow =
                typeOption?.dataset.approvalWorkflow
                ?? '';
            const managerName =
                employeeOption?.dataset.managerName
                ?? approvalPreview.dataset
                    .selfManagerName
                ?? '';
            const hrName =
                typeOption?.dataset.hrApproverName
                ?? '';
            const needsManager = [
                'manager',
                'manager_then_hr',
            ].includes(workflow);
            const needsHr = [
                'hr',
                'manager_then_hr',
            ].includes(workflow);

            if (routeLabel instanceof HTMLElement) {
                routeLabel.textContent =
                    workflowLabels[workflow]
                    ?? 'Select a leave type';
            }

            if (managerLabel instanceof HTMLElement) {
                managerLabel.textContent = needsManager
                    ? `Manager: ${managerName || 'Not assigned'}`
                    : 'Manager: not required';
                managerLabel.classList.toggle(
                    'is-missing',
                    needsManager && managerName === ''
                );
            }

            if (hrLabel instanceof HTMLElement) {
                hrLabel.textContent = needsHr
                    ? `HR: ${hrName || 'Not configured'}`
                    : 'HR: not required';
                hrLabel.classList.toggle(
                    'is-missing',
                    needsHr && hrName === ''
                );
            }

            if (routeHelp instanceof HTMLElement) {
                const missing =
                    (needsManager && managerName === '')
                    || (needsHr && hrName === '');

                routeHelp.textContent = missing
                    ? 'The request cannot be submitted until HR completes the missing approval assignment.'
                    : 'This route will be recorded with the request when you submit it.';
            }
        };

        leaveType.addEventListener(
            'change',
            synchronize
        );

        if (leaveEmployee instanceof HTMLSelectElement) {
            leaveEmployee.addEventListener(
                'change',
                synchronize
            );
        }

        synchronize();
    }

    const calendarScope = document.querySelector(
        '[data-calendar-scope]'
    );

    if (calendarScope instanceof HTMLSelectElement) {
        const employeePanels = document.querySelectorAll(
            '[data-calendar-employee-scope]'
        );
        const companyPanel = document.querySelector(
            '[data-calendar-company-scope]'
        );
        const submitLabel = document.querySelector(
            '[data-calendar-scope-submit]'
        );

        const synchronizeCalendarScope = () => {
            const employeeScope =
                calendarScope.value === 'employee';

            employeePanels.forEach((panel) => {
                if (!(panel instanceof HTMLElement)) {
                    return;
                }

                panel.hidden = !employeeScope;

                panel
                    .querySelectorAll('input, select')
                    .forEach((field) => {
                        if (
                            field instanceof HTMLInputElement
                            || field
                                instanceof HTMLSelectElement
                        ) {
                            field.disabled = !employeeScope;

                            if (
                                field.id === 'schedule-employee'
                                || field.id === 'effective-from'
                            ) {
                                field.required = employeeScope;
                            }
                        }
                    });
            });

            if (companyPanel instanceof HTMLElement) {
                companyPanel.hidden = employeeScope;
            }

            if (submitLabel instanceof HTMLElement) {
                submitLabel.textContent = employeeScope
                    ? 'Assign employee override'
                    : 'Set company default';
            }
        };

        calendarScope.addEventListener(
            'change',
            synchronizeCalendarScope
        );
        synchronizeCalendarScope();
    }

    const attendanceNotification =
        document.querySelector(
            '[data-attendance-notification]'
        );
    const attendanceBrowserButton =
        document.querySelector(
            '[data-enable-attendance-browser]'
        );
    const attendanceBrowserCheckbox =
        document.querySelector(
            '[data-attendance-browser-checkbox]'
        );
    const attendanceBrowserTestButton =
        document.querySelector(
            '[data-test-attendance-browser]'
        );
    const attendanceBrowserStatus =
        document.querySelector(
            '[data-attendance-browser-status]'
        );
    const browserNotificationsSupported =
        'Notification' in window;
    const serviceWorkerSupported =
        'serviceWorker' in navigator
        && window.isSecureContext;
    const appBasePath =
        document.body.dataset.appBase ?? '';
    const serviceWorkerUrl =
        (appBasePath === '' ? '' : appBasePath)
        + '/service-worker.js';
    let attendanceServiceWorker = null;

    const registerAttendanceServiceWorker =
        async () => {
            if (!serviceWorkerSupported) {
                return null;
            }

            if (attendanceServiceWorker !== null) {
                return attendanceServiceWorker;
            }

            try {
                attendanceServiceWorker =
                    await navigator.serviceWorker.register(
                        serviceWorkerUrl
                    );

                return attendanceServiceWorker;
            } catch (error) {
                return null;
            }
        };

    const showAttendanceDeviceNotification =
        async (title, body, tag) => {
            const registration =
                await registerAttendanceServiceWorker();

            if (registration !== null) {
                await registration.showNotification(
                    title,
                    {
                        body,
                        tag,
                    }
                );

                return true;
            }

            try {
                new Notification(title, {
                    body,
                    tag,
                });

                return true;
            } catch (error) {
                return false;
            }
        };

    const setAttendanceBrowserStatus = (
        message
    ) => {
        if (
            attendanceBrowserStatus
                instanceof HTMLElement
        ) {
            attendanceBrowserStatus.textContent =
                message;
        }
    };

    const attendancePush =
        document.querySelector(
            '[data-attendance-push]'
        );

    if (attendancePush instanceof HTMLElement) {
        const pushEnable = attendancePush
            .querySelector(
                '[data-attendance-push-enable]'
            );
        const pushDisable = attendancePush
            .querySelector(
                '[data-attendance-push-disable]'
            );
        const pushStatus = attendancePush
            .querySelector(
                '[data-attendance-push-status]'
            );
        const pushConfigured =
            attendancePush.dataset.configured
                === '1';

        const setPushStatus = (message) => {
            if (pushStatus instanceof HTMLElement) {
                pushStatus.textContent = message;
            }
        };

        const setPushButtons = (subscribed) => {
            if (pushEnable instanceof HTMLButtonElement) {
                pushEnable.disabled =
                    !pushConfigured || subscribed;
            }

            if (pushDisable instanceof HTMLButtonElement) {
                pushDisable.disabled = !subscribed;
            }
        };

        const applicationServerKey = (value) => {
            const padding = '='.repeat(
                (4 - (value.length % 4)) % 4
            );
            const normalized = (value + padding)
                .replace(/-/g, '+')
                .replace(/_/g, '/');
            const raw = window.atob(normalized);
            const bytes = new Uint8Array(raw.length);

            for (
                let index = 0;
                index < raw.length;
                index += 1
            ) {
                bytes[index] = raw.charCodeAt(index);
            }

            return bytes;
        };

        const postSubscription = async (
            url,
            fields
        ) => {
            const response = await window.fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type':
                        'application/x-www-form-urlencoded;charset=UTF-8',
                },
                body: new URLSearchParams({
                    _token:
                        attendancePush.dataset
                            .csrfToken ?? '',
                    ...fields,
                }),
            });
            let result = null;

            try {
                result = await response.json();
            } catch (error) {
                throw new Error(
                    'OfficeApp returned an invalid notification response.'
                );
            }

            if (
                !response.ok
                || result?.successful !== true
            ) {
                throw new Error(
                    result?.message
                    ?? 'The notification request failed.'
                );
            }

            return result;
        };

        const currentPushSubscription =
            async () => {
                const registration =
                    await registerAttendanceServiceWorker();

                if (
                    registration === null
                    || !('pushManager' in registration)
                ) {
                    return null;
                }

                return registration.pushManager
                    .getSubscription();
            };

        const initializePush = async () => {
            if (!pushConfigured) {
                setPushButtons(false);
                return;
            }

            if (
                !browserNotificationsSupported
                || !serviceWorkerSupported
                || !('PushManager' in window)
            ) {
                setPushButtons(false);
                setPushStatus(
                    'This browser cannot receive background notifications. The private inbox remains available.'
                );
                return;
            }

            try {
                const subscription =
                    await currentPushSubscription();
                const subscribed =
                    subscription !== null;

                setPushButtons(subscribed);
                setPushStatus(
                    subscribed
                        ? 'Background notifications are active on this device.'
                        : 'Background notifications are available but not enabled on this device.'
                );
            } catch (error) {
                setPushButtons(false);
                setPushStatus(
                    'OfficeApp could not check this device notification subscription.'
                );
            }
        };

        if (pushEnable instanceof HTMLButtonElement) {
            pushEnable.addEventListener(
                'click',
                async () => {
                    pushEnable.disabled = true;
                    setPushStatus(
                        'Enabling secure background notifications…'
                    );

                    try {
                        const permission =
                            Notification.permission
                                === 'granted'
                                ? 'granted'
                                : await Notification
                                    .requestPermission();

                        if (permission !== 'granted') {
                            throw new Error(
                                'Notification permission was not granted.'
                            );
                        }

                        const registration =
                            await registerAttendanceServiceWorker();

                        if (
                            registration === null
                            || !('pushManager' in registration)
                        ) {
                            throw new Error(
                                'Background notifications are not supported on this device.'
                            );
                        }

                        let subscription =
                            await registration.pushManager
                                .getSubscription();

                        if (subscription === null) {
                            subscription =
                                await registration.pushManager
                                    .subscribe({
                                        userVisibleOnly: true,
                                        applicationServerKey:
                                            applicationServerKey(
                                                attendancePush
                                                    .dataset
                                                    .publicKey
                                                ?? ''
                                            ),
                                    });
                        }

                        const serialized =
                            subscription.toJSON();
                        const encoding =
                            Array.isArray(
                                PushManager
                                    .supportedContentEncodings
                            )
                                ? PushManager
                                    .supportedContentEncodings[0]
                                : 'aes128gcm';

                        await postSubscription(
                            attendancePush.dataset
                                .subscribeUrl ?? '',
                            {
                                endpoint:
                                    subscription.endpoint,
                                p256dh:
                                    serialized.keys?.p256dh
                                    ?? '',
                                auth:
                                    serialized.keys?.auth
                                    ?? '',
                                content_encoding:
                                    encoding,
                            }
                        );

                        setPushButtons(true);
                        setPushStatus(
                            'Background notifications are active on this device.'
                        );
                    } catch (error) {
                        setPushButtons(false);
                        setPushStatus(
                            error instanceof Error
                                ? error.message
                                : 'Background notifications could not be enabled.'
                        );
                    }
                }
            );
        }

        if (pushDisable instanceof HTMLButtonElement) {
            pushDisable.addEventListener(
                'click',
                async () => {
                    pushDisable.disabled = true;
                    setPushStatus(
                        'Disabling this device…'
                    );

                    try {
                        const subscription =
                            await currentPushSubscription();

                        if (subscription !== null) {
                            await postSubscription(
                                attendancePush.dataset
                                    .unsubscribeUrl ?? '',
                                {
                                    endpoint:
                                        subscription.endpoint,
                                }
                            );
                            await subscription.unsubscribe();
                        }

                        setPushButtons(false);
                        setPushStatus(
                            'Background notifications are disabled on this device.'
                        );
                    } catch (error) {
                        setPushButtons(true);
                        setPushStatus(
                            error instanceof Error
                                ? error.message
                                : 'This device could not be disabled.'
                        );
                    }
                }
            );
        }

        initializePush();
    }

    if (
        attendanceBrowserButton
            instanceof HTMLButtonElement
    ) {
        if (!browserNotificationsSupported) {
            attendanceBrowserButton.disabled = true;
            if (
                attendanceBrowserTestButton
                    instanceof HTMLButtonElement
            ) {
                attendanceBrowserTestButton.disabled =
                    true;
            }
            setAttendanceBrowserStatus(
                'This browser does not support local alerts.'
            );
        } else if (!window.isSecureContext) {
            attendanceBrowserButton.disabled = true;
            if (
                attendanceBrowserTestButton
                    instanceof HTMLButtonElement
            ) {
                attendanceBrowserTestButton.disabled =
                    true;
            }
            setAttendanceBrowserStatus(
                'Device alerts require HTTPS outside localhost.'
            );
        } else {
            registerAttendanceServiceWorker();

            if (Notification.permission === 'granted') {
                setAttendanceBrowserStatus(
                    'Device notification permission is enabled.'
                );
            } else if (
                Notification.permission === 'denied'
            ) {
                setAttendanceBrowserStatus(
                    'Browser permission is blocked. Update the site permission in your browser.'
                );
            }

            attendanceBrowserButton.addEventListener(
                'click',
                async () => {
                    const permission =
                        await Notification
                            .requestPermission();

                    if (permission === 'granted') {
                        if (
                            attendanceBrowserCheckbox
                                instanceof HTMLInputElement
                        ) {
                            attendanceBrowserCheckbox
                                .checked = true;
                        }

                        setAttendanceBrowserStatus(
                            'Browser alerts are enabled. Save your settings.'
                        );
                    } else {
                        setAttendanceBrowserStatus(
                            'Browser alert permission was not granted.'
                        );
                    }
                }
            );
        }
    }

    if (
        attendanceBrowserTestButton
            instanceof HTMLButtonElement
        && browserNotificationsSupported
    ) {
        attendanceBrowserTestButton.addEventListener(
            'click',
            async () => {
                if (Notification.permission !== 'granted') {
                    setAttendanceBrowserStatus(
                        'Enable notification permission before sending a test alert.'
                    );

                    return;
                }

                const delivered =
                    await showAttendanceDeviceNotification(
                        'OfficeApp attendance test',
                        'This device can display live attendance alerts while OfficeApp is open.',
                        'attendance:test'
                    );

                setAttendanceBrowserStatus(
                    delivered
                        ? 'Test alert sent to this device.'
                        : 'This device could not display the test alert. The private in-app inbox remains available.'
                );
            }
        );
    }

    if (
        attendanceNotification instanceof HTMLElement
        && attendanceNotification.dataset
            .browserEnabled === '1'
        && browserNotificationsSupported
        && Notification.permission === 'granted'
    ) {
        const notifyAt = Date.parse(
            attendanceNotification.dataset.notifyAt
                ?? ''
        );
        const title =
            attendanceNotification.dataset
                .notificationTitle
            ?? 'Attendance reminder';
        const body =
            attendanceNotification.dataset
                .notificationBody
            ?? '';
        const key =
            attendanceNotification.dataset
                .notificationKey
            ?? '';
        const maximumDelay = 24 * 60 * 60 * 1000;

        const deliverNotification = async () => {
            if (key === '') {
                return;
            }

            try {
                if (
                    window.sessionStorage.getItem(key)
                        === 'delivered'
                ) {
                    return;
                }

                const delivered =
                    await showAttendanceDeviceNotification(
                        title,
                        body,
                        key
                    );

                if (delivered) {
                    window.sessionStorage.setItem(
                        key,
                        'delivered'
                    );
                }
            } catch (error) {
                setAttendanceBrowserStatus(
                    'The private in-app reminder remains active, but this device could not display a live alert.'
                );
            }
        };

        if (Number.isFinite(notifyAt)) {
            const delay = notifyAt - Date.now();

            if (delay <= 0) {
                deliverNotification();
            } else if (delay <= maximumDelay) {
                window.setTimeout(
                    deliverNotification,
                    delay
                );
            }
        }
    }
});

document.querySelectorAll('[data-dynamic-lines]').forEach((editor) => {
    const body = editor.querySelector('[data-line-body]');
    const template = editor.querySelector('[data-line-template]');
    const add = editor.querySelector('[data-add-line]');
    const limit = Number.parseInt(editor.dataset.lineLimit || '50', 10);
    if (!(body instanceof HTMLElement) || !(template instanceof HTMLTemplateElement)) return;

    const renumber = () => {
        body.querySelectorAll('[data-line-row]').forEach((row, index) => {
            row.querySelectorAll('[name], [data-name]').forEach((field) => {
                const key = field.dataset.name || (field.name.match(/\[([^\]]+)\]$/)?.[1] ?? '');
                if (key) field.name = `lines[${index}][${key}]`;
            });
        });
    };
    const recalculate = () => {
        let subtotal = 0, discountTotal = 0, taxTotal = 0;
        body.querySelectorAll('[data-line-row]').forEach((row) => {
            const product = row.querySelector('[data-line-product]');
            const quantity = Number.parseFloat(row.querySelector('[data-line-quantity]')?.value || '0');
            const discount = Number.parseFloat(row.querySelector('[data-line-discount]')?.value || '0');
            const taxRate = Number.parseFloat(row.querySelector('[data-line-tax]')?.value || '0');
            const price = Number.parseFloat(product?.selectedOptions?.[0]?.dataset.price || '0');
            const gross = Math.max(0, quantity) * Math.max(0, price);
            const appliedDiscount = Math.min(Math.max(0, discount), gross);
            const tax = Math.max(0, gross - appliedDiscount) * Math.max(0, taxRate) / 100;
            subtotal += gross; discountTotal += appliedDiscount; taxTotal += tax;
            const priceOutput = row.querySelector('[data-line-price]');
            const totalOutput = row.querySelector('[data-line-total]');
            if (priceOutput) priceOutput.textContent = price.toFixed(2);
            if (totalOutput) totalOutput.textContent = (gross - appliedDiscount + tax).toFixed(2);
        });
        const values = {subtotal, discount: discountTotal, tax: taxTotal, total: subtotal - discountTotal + taxTotal};
        Object.entries(values).forEach(([key, value]) => {
            const output = editor.querySelector(`[data-preview-${key}]`);
            if (output) output.textContent = value.toFixed(2);
        });
    };
    body.addEventListener('input', recalculate);
    body.addEventListener('change', recalculate);
    body.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-remove-line]');
        if (!remove) return;
        const rows = body.querySelectorAll('[data-line-row]');
        if (rows.length === 1) {
            rows[0].querySelectorAll('input, select').forEach((field) => field.value = field.matches('[data-line-discount], [data-line-tax]') ? '0' : '');
        } else remove.closest('[data-line-row]')?.remove();
        renumber(); recalculate();
    });
    add?.addEventListener('click', () => {
        if (body.querySelectorAll('[data-line-row]').length >= limit) return;
        body.append(template.content.cloneNode(true)); renumber(); recalculate();
    });
    renumber(); recalculate();
});
