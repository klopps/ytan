-- Self-service account deletion (todo.md "Kontolöschung"): the mailed
-- confirmation link is a user_token of its own purpose, valid 60 minutes.
ALTER TABLE user_token MODIFY COLUMN purpose ENUM('invite', 'reset', 'email_change', 'account_delete') NOT NULL;
