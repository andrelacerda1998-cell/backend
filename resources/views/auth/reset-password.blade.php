<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Redefinir a palavra-passe</title>
  <style>
    :root {
      --dark: #1B1B1B;
      --gold: #FABB5B;
      --white: #FFFFFF;
      --gray: #858585;
      --gray-light: #BBBBBB;
      --border: #E4E3E3;
      --success: #23E69E;
      --error: #ED4949;
    }
    * { box-sizing: border-box; }
    html, body { height: 100%; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
      background-color: var(--dark);
      color: var(--dark);
      margin: 0;
      padding: 24px;
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100%;
    }
    .card {
      width: 100%;
      max-width: 420px;
      background-color: var(--white);
      border-radius: 20px;
      padding: 32px 24px;
      box-shadow: 0 12px 40px rgba(0, 0, 0, 0.35);
    }
    .brand {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      margin-bottom: 20px;
    }
    .brand-badge {
      background-color: var(--dark);
      color: var(--gold);
      font-weight: 800;
      font-size: 1.25rem;
      letter-spacing: 0.5px;
      padding: 8px 14px;
      border-radius: 12px;
    }
    h1 {
      font-size: 1.4rem;
      font-weight: 700;
      text-align: center;
      margin: 0 0 8px;
      color: var(--dark);
    }
    .subtitle {
      text-align: center;
      color: var(--gray);
      font-size: 0.95rem;
      margin: 0 0 24px;
      line-height: 1.4;
    }
    .btn {
      display: block;
      width: 100%;
      padding: 15px 20px;
      margin-top: 12px;
      border: none;
      border-radius: 14px;
      cursor: pointer;
      font-size: 1rem;
      font-weight: 700;
      text-align: center;
      text-decoration: none;
    }
    .btn-primary {
      background-color: var(--gold);
      color: var(--dark);
    }
    .btn-secondary {
      background-color: transparent;
      color: var(--dark);
      border: 1.5px solid var(--dark);
    }
    .btn:disabled, .btn[disabled] {
      opacity: 0.45;
      cursor: not-allowed;
    }
    .spinner {
      display: inline-block;
      width: 15px;
      height: 15px;
      border: 2px solid rgba(27, 27, 27, 0.3);
      border-top-color: var(--dark);
      border-radius: 50%;
      animation: spin 0.6s linear infinite;
      vertical-align: middle;
      margin-right: 8px;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .success-check {
      width: 64px;
      height: 64px;
      margin: 0 auto 16px;
      border-radius: 50%;
      background-color: rgba(35, 230, 158, 0.15);
      color: var(--success);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 2rem;
    }
    .hint {
      text-align: center;
      color: var(--gray);
      font-size: 0.85rem;
      margin-top: 12px;
    }
    .form-group {
      margin-top: 16px;
      position: relative;
    }
    label {
      display: block;
      margin-bottom: 6px;
      color: var(--dark);
      font-weight: 600;
      font-size: 0.95rem;
    }
    .form-control {
      width: 100%;
      padding: 14px;
      padding-right: 44px;
      border: 1.5px solid var(--border);
      border-radius: 14px;
      background-color: var(--white);
      color: var(--dark);
      font-size: 1rem;
    }
    .form-control:focus {
      outline: none;
      border-color: var(--gold);
    }
    .toggle-password {
      position: absolute;
      top: 34px;
      right: 14px;
      cursor: pointer;
      user-select: none;
    }
    .alert {
      padding: 12px 14px;
      border-radius: 12px;
      margin-bottom: 16px;
      font-size: 0.95rem;
    }
    .alert-success { background-color: rgba(35, 230, 158, 0.15); color: #0f7a55; border: 1px solid var(--success); }
    .alert-danger  { background-color: rgba(237, 73, 73, 0.12);  color: var(--error); border: 1px solid var(--error); }
    .invalid-feedback { display: block; margin: 8px 0; color: var(--error); font-size: 0.9rem; }
    .password-requirements { margin-top: 16px; color: var(--gray); font-size: 0.88em; }
    .password-requirements ul { padding-left: 18px; margin: 8px 0 0; }
    .password-requirements li { margin-bottom: 5px; }
    .fulfilled { color: #0f9d63; }
    .unfulfilled { color: var(--gray-light); }
    .lead { text-align: center; color: var(--gray); margin: 0 0 8px; }
    a.link { color: var(--dark); }
    hr { border: none; border-top: 1px solid var(--border); margin: 24px 0 0; }
  </style>
</head>
<body>
  <div class="card">
    <div class="brand">
      <span class="brand-badge">Piquet</span>
    </div>

    @if (session('status'))
      <div class="success-check">✓</div>
      <h1>Palavra-passe redefinida</h1>
      <p class="subtitle">A sua palavra-passe foi alterada com sucesso. Já pode entrar na aplicação Piquet.</p>
      <a class="btn btn-primary" href="{{ $isVendor ? 'piquet.vendor://signin' : 'piquet.customer://signin' }}">
        Abrir aplicação
      </a>
    @else
      <h1>Redefinir a palavra-passe</h1>
      <p class="subtitle">Abra a aplicação Piquet para continuar, ou redefina aqui no navegador.</p>

      {{-- Interstitial: handoff por gesto do utilizador (botão) em vez do antigo 302 do servidor
           para o esquema custom, que browsers/webviews (ex.: Gmail) recusam → página branca. --}}
      <div id="app-handoff" style="{{ $errors->any() ? 'display:none;' : '' }}">
        <a href="{{ $deepLink }}" class="btn btn-primary" id="open-app-btn">Abrir aplicação</a>
        <button type="button" class="btn btn-secondary" id="show-web-fallback">Redefinir no navegador</button>
      </div>

      <div id="web-fallback" style="{{ $errors->any() ? '' : 'display:none;' }}">
        <hr>
        <form method="POST" action="{{ route('password.update') }}">
          @csrf
          <input type="hidden" name="token" value="{{ $token }}">
          <input id="email" type="hidden" name="email" value="{{ $email ?? old('email') }}">

          <div class="form-group">
            <label for="password">Nova palavra-passe</label>
            <input id="password" type="password" class="form-control @error('password') is-invalid @enderror" name="password" required autocomplete="new-password" minlength="12">
            <span class="toggle-password" onclick="togglePasswordVisibility('password', this)">🔒</span>
          </div>
          @error('password')
            <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
          @enderror

          <div class="form-group">
            <label for="password-confirm">Confirmar nova palavra-passe</label>
            <input id="password-confirm" type="password" class="form-control" name="password_confirmation" required autocomplete="new-password" minlength="12" onpaste="return false;">
            <span class="toggle-password" onclick="togglePasswordVisibility('password-confirm', this)">🔒</span>
          </div>

          <div class="password-requirements">
            <p>A palavra-passe deve conter:</p>
            <ul>
              <li id="length" class="unfulfilled">Pelo menos 12 caracteres</li>
              <li id="uppercase" class="unfulfilled">Pelo menos uma letra maiúscula</li>
              <li id="lowercase" class="unfulfilled">Pelo menos uma letra minúscula</li>
              <li id="number" class="unfulfilled">Pelo menos um número</li>
              <li id="special" class="unfulfilled">Pelo menos um caractere especial (&#64;$!%*?&amp;)</li>
              <li id="common" class="unfulfilled">Sem palavras comuns ou fáceis de adivinhar</li>
              <li id="match" class="unfulfilled">As palavras-passe devem coincidir</li>
            </ul>
          </div>

          <button type="submit" class="btn btn-primary" id="submit-btn">Redefinir palavra-passe</button>
          <p class="hint" id="submit-hint">O botão ativa quando a palavra-passe cumprir todos os requisitos acima.</p>
        </form>
      </div>

      @error('email')
        <div class="alert alert-danger" role="alert">{{ $message }}</div>
      @enderror
    @endif
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const passwordInput = document.getElementById('password');
      const passwordConfirmInput = document.getElementById('password-confirm');
      // Sem formulário (ex.: página de sucesso) não há nada a validar.
      if (!passwordInput || !passwordConfirmInput) return;

      const commonWords = [
        '', 'Password123!', 'Qwerty123!', 'Welcome123!', 'Admin123!', 'User123!',
        'Test123!', 'Example123!', 'Sample123!', 'Demo123!', 'Temp123!',
        'password', '123456', 'qwerty', 'abc123', 'letmein', 'monkey',
        'football', 'iloveyou', 'admin', 'welcome',
        '123456', 'password', '123456789', '12345', '12345678', 'qwerty',
        '1234567', '111111', '1234567890', '123123', 'abc123', '1234',
        'password1', 'iloveyou', '1q2w3e4r', '000000', 'qwerty123', 'zaq12wsx',
        'dragon', 'sunshine', 'princess', 'letmein', '654321', 'monkey',
        '27653', '1qaz2wsx', '123321', 'qwertyuiop', 'superman', 'asdfghjkl'
      ];

      const requirements = {
        length: { element: document.getElementById('length'), regex: /.{12,}/ },
        uppercase: { element: document.getElementById('uppercase'), regex: /[A-Z]/ },
        lowercase: { element: document.getElementById('lowercase'), regex: /[a-z]/ },
        number: { element: document.getElementById('number'), regex: /\d/ },
        special: { element: document.getElementById('special'), regex: /[@$!%*?&]/ },
        match: { element: document.getElementById('match'), validate: (value, confirmValue) => value === confirmValue && value.length > 0 },
        common: { element: document.getElementById('common'), validate: (value) => !commonWords.some(word => value.toLowerCase() === word.toLowerCase()) }
      };
      const form = document.querySelector('form');

      const submitBtn = document.getElementById('submit-btn');
      const submitHint = document.getElementById('submit-hint');

      function validatePassword() {
        const feedback = document.querySelector('.invalid-feedback');
        if (feedback && passwordInput.value.length >= 1) feedback.remove();

        const value = passwordInput.value;
        const confirmValue = passwordConfirmInput.value;

        Object.keys(requirements).forEach(key => {
          const requirement = requirements[key];
          const isValid = requirement.regex ? requirement.regex.test(value) : requirement.validate(value, confirmValue);
          requirement.element.classList.toggle('fulfilled', isValid);
          requirement.element.classList.toggle('unfulfilled', !isValid);
        });

        const allValid = Object.keys(requirements).every(key => requirements[key].element.classList.contains('fulfilled'));
        submitBtn.disabled = !allValid;
        if (submitHint) submitHint.style.display = allValid ? 'none' : 'block';
      }

      passwordInput.addEventListener('input', validatePassword);
      passwordConfirmInput.addEventListener('input', validatePassword);
      validatePassword();

      // Loading ao submeter (evita duplo clique e dá feedback imediato).
      form.addEventListener('submit', function () {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner"></span>A redefinir…';
      });
    });

    function togglePasswordVisibility(id, icon) {
      const input = document.getElementById(id);
      if (input.type === 'password') {
        input.type = 'text';
        icon.textContent = '🔓';
      } else {
        input.type = 'password';
        icon.textContent = '🔒';
      }
    }
  </script>

  @if (!session('status'))
  <script>
    // O handoff para a app acontece por GESTO do utilizador (botão "Abrir aplicação"), em vez do
    // antigo 302 do servidor para o esquema custom, que browsers/webviews (ex.: Gmail) recusam.
    (function () {
      const hasErrors = @json($errors->any());
      const handoff = document.getElementById('app-handoff');
      const webFallback = document.getElementById('web-fallback');
      const showFallbackBtn = document.getElementById('show-web-fallback');

      function revealFallback() {
        if (handoff) handoff.style.display = 'none';
        if (webFallback) webFallback.style.display = 'block';
      }

      // Botão "Redefinir no navegador" → mostra o formulário.
      if (showFallbackBtn) {
        showFallbackBtn.addEventListener('click', function (e) {
          e.preventDefault();
          revealFallback();
        });
      }

      // Se houve erro de validação na submissão, mostramos logo o formulário com o erro.
      if (hasErrors) revealFallback();
    })();
  </script>
  @endif
</body>
</html>
