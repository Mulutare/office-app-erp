# Attendance work-time policy

OfficeApp calculates attendance against the employee's effective workforce
calendar and company timezone. The calendar is the source of truth for each
weekday.

## Multiple work sessions

Employee self-service attendance uses an auditable session timeline beneath
each daily attendance summary:

- the first active session supplies the daily first clock-in;
- each clock-out closes only the current session;
- the employee may clock in again whenever work resumes;
- the most recent completed session supplies the daily latest clock-out;
- payroll work time is the sum of completed active sessions after applicable
  configured lunch overlap is deducted;
- only one active session can be open for an attendance record;
- HR corrections invalidate earlier effective sessions instead of deleting
  their evidence, then create the corrected effective manual interval.

Open sessions do not add stored work minutes until clock-out. This keeps
historical duration snapshots deterministic and auditable.

## Configurable day policy

Each working day defines:

- scheduled start and end;
- lunch start and end;
- maximum unpaid lunch minutes;
- net target minutes, normally `480` for eight working hours;
- flexible arrival minutes.

The standard default is:

```text
Shift:             08:30-17:30
Lunch:             12:30-13:30
Unpaid lunch:      60 minutes
Net target:        480 minutes
Flexible arrival:  30 minutes
```

An employee arriving at 08:45 is therefore within the allowed window. If
that employee checks out at 17:45 and overlaps the full lunch period, the
result is 540 gross minutes minus 60 lunch minutes: 480 net minutes.

## Calculation rules

- Late status begins only after scheduled start plus flexible arrival.
- Lunch is deducted only for the portion of the attendance interval that
  overlaps the configured lunch window, capped by the unpaid-lunch limit.
- Net worked minutes equal gross elapsed minutes minus deducted lunch.
- Work variance equals net worked minutes minus the daily target.
- Expected check-out is calculated from the employee's actual check-in,
  daily target and configured unpaid lunch.
- Overnight shifts and lunch windows are evaluated in the calendar
  timezone.

The attendance record stores the policy results as snapshots. Historical
attendance therefore remains stable when a future calendar is changed.

## Calendar coverage

Every company has one default workforce calendar. It applies to every active
employee unless an effective-dated employee override exists. Overrides are
appropriate for another country, branch, shift pattern or temporary
assignment. This default-plus-exceptions model avoids creating duplicate
schedule rows for the whole workforce while allowing both company-wide and
individual policy.

Changing the company default takes effect immediately for employees without
an override. Employee overrides retain their own start and optional end date.

## Notification delivery

- The server-generated private inbox works on desktop and mobile after the
  employee signs in, including reminders created while the browser was
  closed.
- Live device alerts work on supported desktop and mobile browsers while an
  OfficeApp page remains open. They require HTTPS and device notification
  permission.
- Employees can use **Send test alert** to verify the current device.
- Background operating-system push while the browser is fully closed is not
  enabled until a Web Push subscription and VAPID delivery service are
  configured.

## Production controls

- HR must assign an effective workforce calendar before relying on lateness,
  target or lunch calculations.
- Calendar changes must be reviewed before their effective date.
- Manual attendance uses the same policy service as employee clock actions.
- Payroll integrations should consume net minutes and variance, not recompute
  attendance from raw timestamps.
- Attendance notifications require the scheduled cron command documented in
  `docs/cpanel-deployment.md` or the equivalent container worker.
