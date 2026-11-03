CREATE TABLE IF NOT EXISTS settings (
    setting_key   VARCHAR(50) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS departments (
    id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS holidays (
    holiday_date DATE PRIMARY KEY,
    name         VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS employees (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_no   VARCHAR(30) NOT NULL UNIQUE,
    first_name    VARCHAR(60) NOT NULL,
    last_name     VARCHAR(60) NOT NULL,
    department_id INT UNSIGNED NULL,
    position      VARCHAR(100) NULL,
    email         VARCHAR(120) NULL,
    phone         VARCHAR(30) NULL,
    hire_date     DATE NULL,
    work_start    TIME NULL COMMENT 'NULL = use the default from settings',
    work_end      TIME NULL COMMENT 'NULL = use the default from settings',
    status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at    DATETIME NOT NULL,
    KEY idx_employees_name (last_name, first_name),
    CONSTRAINT fk_employees_department FOREIGN KEY (department_id) REFERENCES departments (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS students (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_no     VARCHAR(30) NOT NULL UNIQUE,
    first_name     VARCHAR(60) NOT NULL,
    last_name      VARCHAR(60) NOT NULL,
    year_level     VARCHAR(30) NULL,
    section        VARCHAR(30) NULL,
    guardian_name  VARCHAR(120) NULL,
    guardian_phone VARCHAR(30) NULL,
    status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at     DATETIME NOT NULL,
    KEY idx_students_name (last_name, first_name),
    KEY idx_students_section (section)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name     VARCHAR(120) NOT NULL,
    role          ENUM('admin','teacher','kiosk','student','employee') NOT NULL,
    employee_id   INT UNSIGNED NULL COMMENT 'Required for teacher and employee logins',
    student_id    INT UNSIGNED NULL COMMENT 'Required for student logins',
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL,
    UNIQUE KEY uq_users_employee (employee_id),
    UNIQUE KEY uq_users_student (student_id),
    CONSTRAINT fk_users_employee FOREIGN KEY (employee_id) REFERENCES employees (id),
    CONSTRAINT fk_users_student FOREIGN KEY (student_id) REFERENCES students (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username     VARCHAR(50) NOT NULL,
    ip_address   VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL,
    KEY idx_login_attempts (username, ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS classes (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_code  VARCHAR(30) NOT NULL UNIQUE,
    subject     VARCHAR(120) NOT NULL,
    section     VARCHAR(30) NULL,
    room        VARCHAR(50) NULL,
    teacher_id  INT UNSIGNED NULL,
    days        VARCHAR(13) NOT NULL COMMENT 'ISO weekdays as CSV: 1=Mon ... 7=Sun',
    start_time  TIME NOT NULL,
    end_time    TIME NOT NULL,
    status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at  DATETIME NOT NULL,
    KEY idx_classes_teacher (teacher_id),
    CONSTRAINT fk_classes_teacher FOREIGN KEY (teacher_id) REFERENCES employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS class_students (
    class_id    INT UNSIGNED NOT NULL,
    student_id  INT UNSIGNED NOT NULL,
    enrolled_on DATE NOT NULL,
    PRIMARY KEY (class_id, student_id),
    KEY idx_class_students_student (student_id),
    CONSTRAINT fk_cs_class FOREIGN KEY (class_id) REFERENCES classes (id),
    CONSTRAINT fk_cs_student FOREIGN KEY (student_id) REFERENCES students (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS employee_attendance (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id       INT UNSIGNED NOT NULL,
    work_date         DATE NOT NULL COMMENT 'The date of the time in, also for overnight shifts',
    time_in           DATETIME NULL,
    time_out          DATETIME NULL,
    status            ENUM('present','late','absent','on_leave') NOT NULL,
    late_minutes      INT UNSIGNED NOT NULL DEFAULT 0,
    undertime_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    worked_minutes    INT UNSIGNED NOT NULL DEFAULT 0,
    remarks           VARCHAR(255) NULL,
    updated_by        INT UNSIGNED NULL COMMENT 'NULL = recorded by the time clock',
    UNIQUE KEY uq_employee_day (employee_id, work_date),
    KEY idx_employee_attendance_date (work_date),
    CONSTRAINT fk_ea_employee FOREIGN KEY (employee_id) REFERENCES employees (id),
    CONSTRAINT fk_ea_user FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS student_attendance (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id     INT UNSIGNED NOT NULL,
    student_id   INT UNSIGNED NOT NULL,
    session_date DATE NOT NULL,
    time_in      DATETIME NULL,
    time_out     DATETIME NULL,
    status       ENUM('present','late','absent','excused') NOT NULL,
    late_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    remarks      VARCHAR(255) NULL,
    updated_by   INT UNSIGNED NULL COMMENT 'NULL = recorded by the time clock',
    UNIQUE KEY uq_student_session (class_id, student_id, session_date),
    KEY idx_student_attendance_student (student_id, session_date),
    KEY idx_student_attendance_date (session_date),
    CONSTRAINT fk_sa_class FOREIGN KEY (class_id) REFERENCES classes (id),
    CONSTRAINT fk_sa_student FOREIGN KEY (student_id) REFERENCES students (id),
    CONSTRAINT fk_sa_user FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS registrations (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_type    ENUM('student','employee') NOT NULL,
    id_number      VARCHAR(30) NOT NULL,
    first_name     VARCHAR(60) NOT NULL,
    last_name      VARCHAR(60) NOT NULL,
    year_level     VARCHAR(30) NULL,
    section        VARCHAR(30) NULL,
    guardian_name  VARCHAR(120) NULL,
    guardian_phone VARCHAR(30) NULL,
    department_id  INT UNSIGNED NULL,
    position       VARCHAR(100) NULL,
    email          VARCHAR(120) NULL,
    phone          VARCHAR(30) NULL,
    password_hash  VARCHAR(255) NULL COMMENT 'Moved to the login on approval, then cleared',
    status         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    ip_address     VARCHAR(45) NOT NULL,
    submitted_at   DATETIME NOT NULL,
    reviewed_by    INT UNSIGNED NULL,
    reviewed_at    DATETIME NULL,
    KEY idx_registrations_status (status, submitted_at),
    KEY idx_registrations_id_number (id_number),
    KEY idx_registrations_ip (ip_address, submitted_at),
    CONSTRAINT fk_registrations_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL,
    CONSTRAINT fk_registrations_user FOREIGN KEY (reviewed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NULL,
    action     VARCHAR(60) NOT NULL,
    details    VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    KEY idx_audit_created (created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('school_name', 'My School'),
    ('work_start', '08:00'),
    ('work_end', '17:00'),
    ('work_days', '1,2,3,4,5'),
    ('employee_grace_minutes', '10'),
    ('class_grace_minutes', '10'),
    ('class_early_minutes', '15');
