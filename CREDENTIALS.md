# Elaka — Local Credentials

Development credentials only. Do not upload this file or use these passwords on a public server.

## Database (XAMPP MariaDB)

| Setting | Value |
|---|---|
| Host | 127.0.0.1 |
| Port | **3307** (3306 is used by the separate MySQL80 Windows service) |
| Database | elaka |
| App user | elaka |
| App password | elaka_pass |
| Root user | root |
| Root password | *(empty)* |

The MySQL80 service on port 3306 is a different server. Its root password is unknown and Elaka does not use it.

## App accounts (http://localhost:8000)

| ID | Name | Email | Password | Role |
|---|---|---|---|---|
| 1 | Elaka Admin | admin@elaka.local | admin123 | admin |
| 2 | Rahim Uddin | rahim@elaka.local | password123 | trusted |
| 3 | Nusrat Jahan | nusrat@elaka.local | password123 | user |
| 4 | Karim Store | karim@elaka.local | password123 | user |
| 5 | Moderator Mitu | mod@elaka.local | password123 | moderator |
| 6 | Ayesha Rahman | test1@elaka.local | password123 | user |
| 7 | Tanvir Hossain | test2@elaka.local | password123 | user |
| 8 | Farhana Akter | test3@elaka.local | password123 | user |
| 9 | Sakib Hasan | test4@elaka.local | password123 | user |
| 10 | Mim Chowdhury | test5@elaka.local | password123 | user |
| 11 | Rafi Ahmed | test6@elaka.local | password123 | user |
| 12 | Sumaiya Islam | test7@elaka.local | password123 | user |
| 13 | Imran Kabir | test8@elaka.local | password123 | user |
| 14 | Nadia Sultana | test9@elaka.local | password123 | user |
| 15 | Arif Mahmud | test10@elaka.local | password123 | user |

In the database, app passwords are stored as secure hashes in `users.password_hash`, not as plain text.

## Starting the project

Run each command in its own terminal:

```
C:\xampp\mysql\bin\mysqld.exe --defaults-file=C:/xampp/mysql/bin/my.ini --port=3307 --standalone
php -S localhost:8000
```

Run the second command inside the project folder. Do not start MySQL from the XAMPP Control Panel: it uses port 3306 and clashes with MySQL80.
