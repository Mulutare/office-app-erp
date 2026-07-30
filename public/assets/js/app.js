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
});
