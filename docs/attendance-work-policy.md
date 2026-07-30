# Attendance work-time policy

OfficeApp calculates attendance against the employee's effective workforce
calendar and company timezone. The calendar is the source of truth for each
weekday.

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

## Production controls

- HR must assign an effective workforce calendar before relying on lateness,
  target or lunch calculations.
- Calendar changes must be reviewed before their effective date.
- Manual attendance uses the same policy service as employee clock actions.
- Payroll integrations should consume net minutes and variance, not recompute
  attendance from raw timestamps.
- Attendance notifications require the scheduled cron command documented in
  `docs/cpanel-deployment.md` or the equivalent container worker.
