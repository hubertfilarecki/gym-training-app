# Aplikacja do planowania treningów / Workout Planning Application

Webowa aplikacja w PHP i MySQL do układania planów treningowych, śledzenia progresu i komunikacji między użytkownikami. Projekt powstał jako praca inżynierska - nacisk położony na funkcjonalności backendowe i praktyczną naukę budowania większych aplikacji webowych.

A web application in PHP and MySQL for creating training plans, tracking progress, and communication between users. Developed as an engineering thesis - with a focus on backend features and hands-on learning building a larger web app.

---

## Preview

![Widok głównego panelu / Main panel view](./docs/assets/plany-treningowe.png)

### Galeria / Gallery

![Tworzenie planu / Creating a plan](./docs/assets/tworzenie-planu.png)
![Komunikator / Messenger](./docs/assets/komunikator.png)
![Wykresy postępów / Progress charts](./docs/assets/wykresy.png)
![Goście portalu / Portal guests](./docs/assets/goscie-portalu.png)

---

## Demo

[Link do strony / Link to the site](https://hubfil.great-site.net/inz/logowanie.php)

> Demo aplikacji dostępne na zewnętrznym hostingu.

> The demo of the application is hosted on an external server.

**Konto demo / Demo account:**

* Login: `user1`
* Hasło / Password: `pass1`

---

## Polski

### Funkcje

* Rejestracja i logowanie użytkowników
* System sesji i ról
* Tworzenie oraz edycja planów treningowych
* Historia treningów i śledzenie progresu
* Statystyki i wykresy postępów
* Prywatny komunikator między użytkownikami
* Publiczny chat
* Upload zdjęć i plików
* Panel administratora
* Logowanie aktywności użytkowników
* Responsywny interfejs (Bootstrap)

---

### Technologie

* Backend: PHP, MySQL / MariaDB
* Frontend: HTML5, CSS3, Bootstrap, JavaScript (AJAX)

---

### Uruchomienie lokalne

```bash
git clone https://github.com/hubertfilarecki/gym-training-app.git
```

1. Utwórz plik `.env` w głównym katalogu projektu.
2. Uzupełnij dane połączenia z bazą danych (wzór poniżej).
3. Zaimportuj schemat bazy MySQL.
4. Uruchom projekt na lokalnym serwerze PHP (XAMPP, Laragon itp.).

Przykładowy `.env`:

```env
DB_HOST=localhost
DB_PORT=3306
DB_NAME=twoja_baza
DB_USER=twoj_user
DB_PASSWORD=twoje_haslo
```

---

### Struktura projektu

```txt
app/
├── bootstrap/
└── helpers/

docs/
└── assets/

uploads/

plany.php
progres.php
komunikator.php
logowanie.php
rejestruj.php
```

---

### Bezpieczeństwo

* Dane dostępowe do bazy przechowywane lokalnie w `.env`
* Plik `.env` oraz katalog `uploads/` są wykluczone z repozytorium
* Repozytorium nie zawiera żadnych prywatnych danych ani sekretów

---

### Zakres i ograniczenia

Aplikacja powstawała bez frameworka MVC, dlatego część logiki pozostała w większych plikach. Priorytetem była funkcjonalność i nauka - nie pełna optymalizacja architektury. Pod koniec projektu przeprowadzony został częściowy refactor: wydzielenie helperów, uporządkowanie inicjalizacji i ograniczenie duplikacji kodu.

---

### Czego się nauczyłem

* Budowania aplikacji webowych w PHP od podstaw
* Pracy z MySQL i modelowania relacji w bazie danych
* Obsługi sesji i autoryzacji użytkowników
* Komunikacji AJAX bez przeładowywania strony
* Uploadu plików i walidacji danych po stronie serwera
* Organizacji i refactoru większego projektu PHP
* Tworzenia responsywnego interfejsu z Bootstrap

---

### Licencja

MIT

---

## English

### Features

* User registration and login
* Session and role management
* Create and edit workout plans
* Training history and progress tracking
* Statistics and progress charts
* Private messaging between users
* Public chat
* File and image uploads
* Admin panel
* User activity logging
* Responsive UI (Bootstrap)

---

### Technologies

* Backend: PHP, MySQL / MariaDB
* Frontend: HTML5, CSS3, Bootstrap, JavaScript (AJAX)

---

### How to run locally

```bash
git clone https://github.com/hubertfilarecki/gym-training-app.git
```

1. Create a `.env` file in the project root.
2. Fill in the database connection settings (example below).
3. Import the MySQL database schema.
4. Run the project on a local PHP server (XAMPP, Laragon, etc.).

Sample `.env`:

```env
DB_HOST=localhost
DB_PORT=3306
DB_NAME=your_database
DB_USER=your_user
DB_PASSWORD=your_password
```

---

### Project structure

```txt
app/
├── bootstrap/
└── helpers/

docs/
└── assets/

uploads/

plany.php
progres.php
komunikator.php
logowanie.php
rejestruj.php
```

---

### Security

* Database credentials are stored locally in `.env`
* The `.env` file and the `uploads/` directory are excluded from the repository
* The repo does not contain any private data or secrets

---

### Scope & limitations

The application was developed without an MVC framework, so some logic remains inside larger files. Priority was on functionality and learning rather than perfect architecture. A partial refactor was performed near the end of the project: helpers were extracted, initialization was organized, and code duplication was reduced.

---

### What I learned

* Building web applications in PHP from scratch
* Working with MySQL and modeling relational data
* Session handling and user authorization
* AJAX-based communication without page reloads
* File upload handling and server-side validation
* Organizing and refactoring a larger PHP project
* Creating a responsive UI with Bootstrap

---

### License

MIT
