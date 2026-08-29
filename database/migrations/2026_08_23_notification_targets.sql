ALTER TABLE notifications ADD COLUMN target_url VARCHAR(500) NULL AFTER message;
CREATE INDEX idx_notifications_user_status ON notifications (user_id, status, created_at);
