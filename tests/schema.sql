create table users
(
    id              int auto_increment                        primary key,
    username        varchar(45)                               null,
    email           varchar(100)                              not null,
    password        varchar(150)                              not null,
    google_id       varchar(100)                              null,
    firstname       varchar(150)                              null,
    lastname        varchar(150)                              null,
    type_id         tinyint                                   not null,
    role_id         smallint                                  not null,
    avatar          varchar(100) default 'avatar-default.svg' null,
    country_id      int                                       null,
    state_id        int                                       null,
    city_id         int                                       null,
    street          varchar(255)                              null,
    number          varchar(255)                              null,
    zip_code        varchar(45)                               null,
    last_visit_date timestamp                                 null,
    remember_token  varchar(100)                              null,
    created_at      timestamp                                 null,
    updated_at      timestamp                                 null,
    closed_at       timestamp                                 null,
    active          tinyint      default 1                    null
);


create table roles
(
    id          smallint     auto_increment primary key,
    name        varchar(100) not null,
    description varchar(255) null
);
