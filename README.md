# general-pos
/general-pos
├── public/
│   ├── index.php            (login page)
│   ├── dashboard.php        (main app after login)
│   ├── logout.php
│   ├── api/
│   │   ├── login.php
│   │   ├── products.php
│   │   ├── sales.php
│   │   └── receipt.php
│   └── assets/
│       ├── css/
│       └── js/
├── inc/
│   ├── db.php              (PDO connection)
│   ├── auth.php            (auth helper functions)
│   └── mpesa.php           (MPESA API helpers - simulated)
└── vendor/                 (optional for composer libs)
