-- Run before deploying the step-count API extension. No existing rows are changed.
CREATE TABLE IF NOT EXISTS trip_day_steps (
  trip_id CHAR(36) NOT NULL,
  day_date DATE NOT NULL,
  payload TEXT NOT NULL,
  PRIMARY KEY (trip_id, day_date)
) ENGINE=InnoDB;
