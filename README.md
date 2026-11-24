# GIAEConnect

> [!WARNING]
> I no longer attend a school using GIAE and do not have access to an active instance for testing. I still review pull requests and issues, but I cannot verify fixes against live accounts. If you use GIAE and want to help maintain this project, please get in touch.

This PHP class provides an unofficial API for the GIAE platform (Gestão Integrada para Administração Escolar), widely used in Portuguese public schools, on behalf of a given user.

## Requirements

- PHP 8.1+
- PHP extensions: `curl`, `json`, `mbstring`

## Installation

### With Composer (recommended)

Run the following command inside your project directory:

```bash
composer require juoum/giaeconnect
```

Then include the Composer autoloader:

```php
require_once __DIR__ . '/vendor/autoload.php';
```

### Standalone

Download `GiaeConnect.php` from the latest release and require it directly:

```php
require_once __DIR__ . '/GiaeConnect.php';
```

## Quickstart

```php
use juoum\GiaeConnect\GiaeConnect;

// Initialize with school domain and user credentials
$giae = new GiaeConnect('giae.example.pt', 'student_number', 'password');

// Check card balance
$saldo = $giae->getSaldo();

// Get class schedule and grades
$horario = $giae->getHorario();
$avaliacoes = $giae->getAvaliacoes();
```

For schools using self-signed internal certificates, pass `false` as the fourth parameter:

```php
$giae = new GiaeConnect('giae.example.pt', 'student_number', 'password', false);
```

## Features

- **Account & Card:** Balance (`getSaldo`), card transactions (`getMovimentosCartao`), spending limits (`getLimitesConsumo`).
- **Academic:** Class schedule (`getHorario`), absences (`getFaltas`), lesson summaries (`getSumarios`), tests (`getTestes`), grades and evaluations (`getAvaliacoes`).
- **Canteen & Meals:** Canteen menus (`getEmentas`), meal purchasing (`getRefeicoesComprar`), served meals (`getRefeicoesServidas`).
- **School & Notices:** Announcements (`getAvisos`), school messages (`getMensagensAtivas`), gate entry and exit records (`getPortaria`), contacts (`getContactos`).
- **Account Management:** Password change (`changePass`), PIN change (`changePin`).

Check the [wiki](https://github.com/itsjuoum/GIAEConnect/wiki) for more detailed documentation.

## Contributions

Contributions and bug reports are welcome. If you have improvements, fixes, or new features to propose, please submit a pull request or open an issue.

<hr>

Developed on v5.0.55.6.  
Last tested on v5.0.60.4.
