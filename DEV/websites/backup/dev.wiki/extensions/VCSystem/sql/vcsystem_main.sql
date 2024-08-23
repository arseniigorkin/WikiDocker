CREATE TABLE /*_*/vcsystem_main (
    id INT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    owner_usr_id INT UNSIGNED NOT NULL,
    ctd_id INT UNSIGNED,
    vc_timestamp TEXT NOT NULL,
    isActive BOOL DEFAULT 1
) /*$wgDBTableOptions*/;