# Attendance Monitoring System

Time in / time out tracking for employees and students, with class attendance and reports.
Built with plain PHP 8.1+ and MySQL/MariaDB. It needs no Composer packages or internet access.

## Install (XAMPP)

1. Start Apache and MySQL in the XAMPP Control Panel.
2. Check `config/config.php`, especially `timezone`.
3. Open <http://localhost/attendances_system/install.php>, create your administrator account, and optionally load sample data.

The install page locks itself once an account exists. To start over, drop the `attendances_system` database and open it again.

## Accounts

| Role | Can do |
| --- | --- |
| Administrator | Everything |
| Teacher | Take attendance and run reports for classes assigned to them, and view their own attendance. The account must be linked to an employee record. |
| Employee | Dashboard with today's status, totals and records. Can export or print their own attendance, view their details and change their password. |
| Student | Dashboard with enrolled subjects, today's status, totals and records. Can export or print their own attendance, view their details and change their password. |
| Time clock kiosk | Use the time clock only |

The login page has three tabs. Students use the Student tab, employees and teachers use the Employee tab, and administrators and kiosks use the Admin tab. Attendance is only recorded at the time clock, so a personal login cannot time anyone in or out.

## Registration

Students and employees sign up at `register.php`, linked from the login page, and choose a password. A sign-up does nothing until an admin approves it under Registrations, which creates the student or employee record and a login that uses their ID number. Approved students still need enrolling in their classes. Approved employees get today as their hire date.

Admins can also add people directly from the Students and Employees pages, then create their login under Users.

## Daily use

**Time clock (`kiosk.php`).** Log in as a kiosk account on the gate PC. People scan or type their ID. USB barcode and RFID readers that act as a keyboard work without setup. The first scan is a time in and the next is a time out.

- Employees are marked late after their shift start plus the grace period. Undertime is measured against the shift end.
- Overnight shifts work: set a shift end earlier than its start on the employee record.
- Students are matched to the class in session. Time in opens before class (15 minutes by default).
- A repeat scan within 60 seconds is ignored, so a double scan does not time someone out.

**Corrections.** Admins fix employee records in Employee Attendance. Teachers and admins fix student records in Class Attendance. Admin changes to attendance, people and settings appear in the Audit Log.

**Absences.** Nobody has to enter these. Reports count a working day or class session with no record as absent, skipping holidays and dates before the hire or enrolment date. Today counts only once it is over (for classes, once the class has ended).

## Setup order

1. Settings: school name, work hours, grace periods, departments, holidays
2. Employees
3. Students
4. Classes, then enrol students from the Classes page
5. Users: a teacher login per teacher and one kiosk login per gate

## Before going beyond localhost

- Set a MySQL password and a dedicated database user in `config/config.php`.
- Serve the site over HTTPS.
- Confirm Apache honours `.htaccess` (`AllowOverride All`), which blocks `config/`, `database/` and `includes/`.
- Back up the database regularly.
