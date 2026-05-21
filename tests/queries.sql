-- @name ListUsers
-- @group User
-- @returns :many
SELECT users.* FROM users;


-- @name GetUser
-- @group User
-- @returns :one
SELECT users.* FROM users WHERE users.id = :id;


-- @name GetUserByEmail
-- @group User
-- @returns :opt
SELECT users.* FROM users WHERE users.email = :email;


-- @name DeleteUser
-- @group User
-- @returns :exec
DELETE FROM users WHERE id = :id;


-- @name UpdateUserActive
-- @group User
-- @returns :exec
UPDATE users SET active = :active, updated_at = :updatedAt WHERE id = :id;


-- @name GetUserProfile
-- @group User
-- @returns :one
SELECT
    users.id,
    users.username,
    users.email,
    users.firstname,
    users.lastname,
    users.avatar
FROM users
WHERE users.id = :id;


-- @name SearchUsers
-- @group User
-- @returns :many
SELECT
    users.id,
    users.username,
    users.email,
    users.firstname,
    users.lastname,
    users.active,
    users.created_at
FROM users
WHERE users.active = :active
ORDER BY users.created_at DESC;


-- @name GetUserWithRole
-- @group User
-- @returns :one
SELECT
    users.id,
    users.username,
    users.email,
    roles.name    AS role_name,
    roles.description AS role_description
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE users.id = :id;


-- @name ListActiveUsersWithRole
-- @group User
-- @returns :many
SELECT
    users.id,
    users.username,
    users.email,
    users.firstname,
    users.lastname,
    roles.name AS role_name
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE users.active = :active;


-- @name GetUserStats
-- @group User
-- @returns :one
SELECT
    COUNT(*)              AS total_users,
    SUM(active)           AS total_active,
    AVG(role_id)          AS avg_role,
    MAX(created_at)       AS last_signup,
    MIN(created_at)       AS first_signup
FROM users;


-- @name GetUserSummary
-- @group User
-- @returns :one
SELECT
    users.id,
    CONCAT(users.firstname, ' ', users.lastname) AS full_name,
    COALESCE(users.username, users.email)         AS display_name,
    COUNT(*)                                      AS login_count,
    CASE WHEN users.active = 1 THEN 'active' ELSE 'inactive' END AS status
FROM users
WHERE users.id = :id;
