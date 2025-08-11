<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Kenya POS - Login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
</head>
<body class="bg-light d-flex justify-content-center align-items-center vh-100">
  <div class="card p-4 shadow-sm" style="width: 320px;">
    <h3 class="mb-3 text-center">Login</h3>
    <form id="loginForm">
      <div class="mb-3">
        <label for="username" class="form-label">Username</label>
        <input type="text" id="username" class="form-control" required autofocus />
      </div>
      <div class="mb-3">
        <label for="password" class="form-label">Password</label>
        <input type="password" id="password" class="form-control" required />
      </div>
      <button type="submit" class="btn btn-primary w-100">Login</button>
    </form>
    <div id="msg" class="mt-2 text-danger text-center"></div>
  </div>

  <script>
    const form = document.getElementById('loginForm');
    const msg = document.getElementById('msg');

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      msg.textContent = '';
      const username = form.username.value.trim();
      const password = form.password.value;

      try {
        const res = await fetch('api/login.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({username, password})
        });
        const data = await res.json();

        if (res.ok) {
          window.location.href = 'dashboard.php';
        } else {
          msg.textContent = data.error || 'Login failed';
        }
      } catch (err) {
        msg.textContent = 'Network error';
      }
    });
  </script>
</body>
</html>
