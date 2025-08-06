-- Insert new admin user with username 'admin' and password 'admin123'
-- Password is hashed using bcrypt

INSERT INTO admin_users (username, email, password, full_name, role, is_active) 
VALUES (
    'admin', 
    'admin@kgxesports.com', 
    '$2y$10$wJs7NUXFoPiI2G5BzVrLqOoTrkj3wMm0WLlhPObNAV40v1A3oN1Hi', 
    'KGX Admin', 
    'super_admin',
    true
);

-- If you want to create an additional admin user with a different username:
-- INSERT INTO admin_users (username, email, password, full_name, role, is_active) 
-- VALUES (
--     'kgx_admin', 
--     'kgx_admin@kgxesports.com', 
--     '$2y$10$wJs7NUXFoPiI2G5BzVrLqOoTrkj3wMm0WLlhPObNAV40v1A3oN1Hi', 
--     'KGX System Administrator', 
--     'super_admin',
--     true
-- );

-- Alternative: Update existing admin user if it exists
-- UPDATE admin_users 
-- SET 
--     password = '$2y$10$wJs7NUXFoPiI2G5BzVrLqOoTrkj3wMm0WLlhPObNAV40v1A3oN1Hi',
--     email = 'admin@kgxesports.com',
--     full_name = 'KGX Admin',
--     is_active = true
-- WHERE username = 'admin';
