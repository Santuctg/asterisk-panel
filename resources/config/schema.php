<?php

declare(strict_types=1);

/**
 * Database schema.
 *
 * Loaded by scripts/install.php (fresh install) and referenced by
 * scripts/migrate.php for upgrades. Written to be portable across SQLite
 * (default) and MySQL — only simple column types are used.
 *
 * Multi-tenancy model: every tenant has a unique Asterisk `context`
 * (e.g. "acme", "globex"). All per-business rows carry a tenant_id, and the
 * ConfigGenerator namespaces every Asterisk section by that context, so two
 * tenants can both use extension 1001 without collision.
 */

return [

    'tenants' => '
        CREATE TABLE IF NOT EXISTS tenants (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            name            VARCHAR(120) NOT NULL,
            context         VARCHAR(60)  NOT NULL UNIQUE,
            domain          VARCHAR(160),
            contact_email   VARCHAR(160),
            contact_phone   VARCHAR(40),
            provision_url   VARCHAR(255),
            timezone        VARCHAR(60) DEFAULT "America/New_York",
            enabled         INTEGER DEFAULT 1,
            notes           TEXT,
            created_at      DATETIME,
            updated_at      DATETIME
        )',

    'users' => '
        CREATE TABLE IF NOT EXISTS users (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id       INTEGER REFERENCES tenants(id) ON DELETE CASCADE,
            username        VARCHAR(80) NOT NULL,
            email           VARCHAR(160),
            password        VARCHAR(255) NOT NULL,
            role            VARCHAR(20) DEFAULT "user",
            active          INTEGER DEFAULT 1,
            last_login      DATETIME,
            last_login_ip   VARCHAR(45),
            created_at      DATETIME,
            updated_at      DATETIME,
            UNIQUE(tenant_id, username)
        )',

    'roles' => '
        CREATE TABLE IF NOT EXISTS roles (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id   INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
            name        VARCHAR(80) NOT NULL,
            slug        VARCHAR(20) NOT NULL,
            permissions TEXT NOT NULL DEFAULT "[]",
            created_at  DATETIME,
            updated_at  DATETIME,
            UNIQUE(tenant_id, name),
            UNIQUE(tenant_id, slug)
        )',

    'tenant_login_bans' => '
        CREATE TABLE IF NOT EXISTS tenant_login_bans (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id  INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
            ip         VARCHAR(45) NOT NULL,
            reason     VARCHAR(160),
            created_by INTEGER,
            created_at DATETIME,
            UNIQUE(tenant_id, ip)
        )',

    'extensions' => '
        CREATE TABLE IF NOT EXISTS extensions (
            id                 INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id          INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
            extension          VARCHAR(20) NOT NULL,
            name               VARCHAR(120) NOT NULL,
            secret             VARCHAR(128) NOT NULL,
            cid_name           VARCHAR(120),
            cid_number         VARCHAR(40),
            vm_enabled         INTEGER DEFAULT 1,
            vm_on_busy         INTEGER DEFAULT 1,
            vm_on_noanswer     INTEGER DEFAULT 1,
            ring_timeout       INTEGER DEFAULT 25,
            max_registrations  INTEGER DEFAULT 1,
            follow_me          INTEGER DEFAULT 0,
            enabled            INTEGER DEFAULT 1,
            description        TEXT,
            record             INTEGER DEFAULT 0,
            created_at         DATETIME,
            updated_at         DATETIME,
            UNIQUE(tenant_id, extension)
        )',

    'voicemail' => '
        CREATE TABLE IF NOT EXISTS voicemail (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id         INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
            extension_id      INTEGER NOT NULL REFERENCES extensions(id) ON DELETE CASCADE,
            pin               VARCHAR(20) NOT NULL,
            name              VARCHAR(120) NOT NULL,
            email             VARCHAR(160),
            pager_email       VARCHAR(160),
            vm_password       VARCHAR(40),
            attach            INTEGER DEFAULT 1,
            say_cid           INTEGER DEFAULT 1,
            envelope          INTEGER DEFAULT 1,
            skip_instructions INTEGER DEFAULT 0,
            timezone          VARCHAR(60) DEFAULT "America/New_York",
            enabled           INTEGER DEFAULT 1,
            created_at        DATETIME,
            updated_at        DATETIME
        )',

    'trunks' => '
        CREATE TABLE IF NOT EXISTS trunks (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id   INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
            name        VARCHAR(120) NOT NULL,
            host        VARCHAR(160) NOT NULL,
            username    VARCHAR(120),
            secret      VARCHAR(160),
            from_domain VARCHAR(160),
            cid         VARCHAR(60),
            register    INTEGER DEFAULT 300,
            enabled     INTEGER DEFAULT 1,
            created_at  DATETIME,
            updated_at  DATETIME
        )',

    'devices' => '
        CREATE TABLE IF NOT EXISTS devices (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id      INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
            extension_id   INTEGER REFERENCES extensions(id) ON DELETE SET NULL,
            mac            VARCHAR(32) NOT NULL UNIQUE,
            vendor         VARCHAR(40) DEFAULT "generic",
            model          VARCHAR(80),
            description    VARCHAR(160),
            firmware       VARCHAR(80),
            provision_path VARCHAR(255),
            enabled        INTEGER DEFAULT 1,
            created_at     DATETIME,
            updated_at     DATETIME
        )',

    'ring_groups' => '
        CREATE TABLE IF NOT EXISTS ring_groups (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id    INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
            extension    VARCHAR(20) NOT NULL,
            name         VARCHAR(120) NOT NULL,
            strategy     VARCHAR(30) DEFAULT "ringall",
            timeout      INTEGER DEFAULT 30,
            ringback     VARCHAR(80),
            record       INTEGER DEFAULT 0,
            enabled      INTEGER DEFAULT 1,
            created_at   DATETIME,
            updated_at   DATETIME,
            UNIQUE(tenant_id, extension)
        )',

    'ring_group_members' => '
        CREATE TABLE IF NOT EXISTS ring_group_members (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            ring_group_id INTEGER NOT NULL REFERENCES ring_groups(id) ON DELETE CASCADE,
            extension_id  INTEGER NOT NULL REFERENCES extensions(id) ON DELETE CASCADE,
            ordering      INTEGER DEFAULT 0
        )',

    'parking_lots' => '
        CREATE TABLE IF NOT EXISTS parking_lots (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id         INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
            name              VARCHAR(120) NOT NULL,
            extension         VARCHAR(20) NOT NULL,
            parked_range      VARCHAR(40) DEFAULT "701-750",
            return_extension  VARCHAR(20),
            timeout           INTEGER DEFAULT 45,
            courtesy_tone     VARCHAR(40) DEFAULT "beep",
            allow_transfer    INTEGER DEFAULT 1,
            allow_repark      INTEGER DEFAULT 1,
            allow_hangup      INTEGER DEFAULT 0,
            play_sounds       INTEGER DEFAULT 1,
            record            INTEGER DEFAULT 0,
            enabled           INTEGER DEFAULT 1,
            created_at        DATETIME,
            updated_at        DATETIME
        )',

    'ivr_menus' => '
        CREATE TABLE IF NOT EXISTS ivr_menus (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id        INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
            extension        VARCHAR(20) NOT NULL,
            name             VARCHAR(120) NOT NULL,
            greeting         VARCHAR(255),
            timeout          INTEGER DEFAULT 3,
            response_timeout INTEGER DEFAULT 8,
            max_retries      INTEGER DEFAULT 3,
            keys             TEXT,
            timeout_action   VARCHAR(30),
            timeout_dest_id  INTEGER,
            record           INTEGER DEFAULT 0,
            enabled          INTEGER DEFAULT 1,
            created_at       DATETIME,
            updated_at       DATETIME,
            UNIQUE(tenant_id, extension)
        )',

    'inbound_routes' => '
        CREATE TABLE IF NOT EXISTS inbound_routes (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id   INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
            name        VARCHAR(120) NOT NULL,
            did_pattern VARCHAR(60) NOT NULL,
            cid_pattern VARCHAR(60),
            dest_type   VARCHAR(30) NOT NULL,
            dest_id     INTEGER,
            priority    INTEGER DEFAULT 0,
            enabled     INTEGER DEFAULT 1,
            record      INTEGER DEFAULT 0,
            created_at  DATETIME,
            updated_at  DATETIME
        )',

    'outbound_routes' => '
        CREATE TABLE IF NOT EXISTS outbound_routes (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id     INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
            name          VARCHAR(120) NOT NULL,
            prefix        VARCHAR(20),
            match_pattern VARCHAR(60) NOT NULL,
            trunk_id      INTEGER REFERENCES trunks(id) ON DELETE SET NULL,
            cid_override  VARCHAR(60),
            priority      INTEGER DEFAULT 0,
            enabled       INTEGER DEFAULT 1,
            record        INTEGER DEFAULT 0,
            created_at    DATETIME,
            updated_at    DATETIME
        )',

    'time_conditions' => '
        CREATE TABLE IF NOT EXISTS time_conditions (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id       INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
            extension       VARCHAR(20),
            name            VARCHAR(120) NOT NULL,
            days_of_week    VARCHAR(40) DEFAULT "*",
            day_of_month    VARCHAR(20) DEFAULT "*",
            month           VARCHAR(20) DEFAULT "*",
            start_hour      INTEGER,
            start_minute    INTEGER DEFAULT 0,
            end_hour        INTEGER,
            end_minute      INTEGER DEFAULT 0,
            time_match      VARCHAR(160),
            open_type       VARCHAR(30),
            open_dest_id    INTEGER,
            closed_type     VARCHAR(30),
            closed_dest_id  INTEGER,
            priority        INTEGER DEFAULT 0,
            enabled         INTEGER DEFAULT 1,
            record          INTEGER DEFAULT 0,
            created_at      DATETIME,
            updated_at      DATETIME
        )',

    'moh_classes' => '
        CREATE TABLE IF NOT EXISTS moh_classes (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       VARCHAR(80) NOT NULL UNIQUE,
            directory  VARCHAR(255),
            random     INTEGER DEFAULT 1,
            shuffle    INTEGER DEFAULT 0,
            created_at DATETIME,
            updated_at DATETIME
        )',

    'queues' => '
        CREATE TABLE IF NOT EXISTS queues (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id       INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
            extension       VARCHAR(20) NOT NULL,
            name            VARCHAR(120) NOT NULL,
            strategy        VARCHAR(30) DEFAULT "ringall",
            timeout         INTEGER DEFAULT 15,
            max_wait        INTEGER DEFAULT 300,
            service_level   INTEGER DEFAULT 60,
            wrapup          INTEGER DEFAULT 5,
            retry           INTEGER DEFAULT 5,
            member_delay    INTEGER DEFAULT 0,
            agent_announce  VARCHAR(120),
            moh_class       VARCHAR(80) DEFAULT "default",
            record          INTEGER DEFAULT 0,
            enabled         INTEGER DEFAULT 1,
            created_at      DATETIME,
            updated_at      DATETIME,
            UNIQUE(tenant_id, extension)
        )',

    'queue_agents' => '
        CREATE TABLE IF NOT EXISTS queue_agents (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            queue_id     INTEGER NOT NULL REFERENCES queues(id) ON DELETE CASCADE,
            extension_id INTEGER NOT NULL REFERENCES extensions(id) ON DELETE CASCADE,
            penalty      INTEGER DEFAULT 0,
            paused       INTEGER DEFAULT 0,
            created_at   DATETIME,
            UNIQUE(queue_id, extension_id)
        )',

    'conferences' => '
        CREATE TABLE IF NOT EXISTS conferences (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id   INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
            extension   VARCHAR(20) NOT NULL,
            name        VARCHAR(120) NOT NULL,
            pin         VARCHAR(20),
            admin_pin   VARCHAR(20),
            profile     VARCHAR(40) DEFAULT "default",
            max_members INTEGER DEFAULT 0,
            moh_class   VARCHAR(80) DEFAULT "default",
            record      INTEGER DEFAULT 0,
            enabled     INTEGER DEFAULT 1,
            created_at  DATETIME,
            updated_at  DATETIME,
            UNIQUE(tenant_id, extension)
        )',

    'settings' => '
        CREATE TABLE IF NOT EXISTS settings (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id  INTEGER REFERENCES tenants(id) ON DELETE CASCADE,
            key        VARCHAR(80) NOT NULL,
            value      TEXT,
            created_at DATETIME,
            updated_at DATETIME
        )',

    'audit_log' => '
        CREATE TABLE IF NOT EXISTS audit_log (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER,
            action     VARCHAR(120) NOT NULL,
            entity     VARCHAR(60),
            entity_id  INTEGER,
            detail     TEXT,
            ip         VARCHAR(45),
            created_at DATETIME
        )',

    'cdr' => '
        CREATE TABLE IF NOT EXISTS cdr (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id    INTEGER,
            calldate     DATETIME,
            src          VARCHAR(60),
            dst          VARCHAR(60),
            dcontext     VARCHAR(80),
            channel      VARCHAR(120),
            dstchannel   VARCHAR(120),
            lastapp      VARCHAR(60),
            lastdata     VARCHAR(120),
            duration     INTEGER DEFAULT 0,
            billsec      INTEGER DEFAULT 0,
            disposition  VARCHAR(30),
            amaflags     VARCHAR(20),
            accountcode  VARCHAR(60),
            uniqueid     VARCHAR(60),
            userfield    VARCHAR(255)
        )',

];
