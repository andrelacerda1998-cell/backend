<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Email Verification</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      background-color: #FFFFFF;
      color: #1B1B1B;
      margin: 0;
      padding: 0;
      word-break: break-all;
    }
    .container {
      max-width: 600px;
      margin: 0 auto;
      padding: 20px;
      background-color: #FFFFFF;
      border-radius: 8px;
    }
    .header {
      text-align: center;
      margin-bottom: 20px;
    }
    .header h1 {
      color: #1B1B1B;
    }
    .message {
      color: #E4E3E3;
      margin-bottom: 20px;
    }
    .button {
      display: inline-block;
      padding: 10px 20px;
      background-color: #FABB5A;
      color: #1B1B1B;
      text-decoration: none;
      border-radius: 5px;
    }
    .button:hover {
      background-color: #E0A84C;
    }
    .footer {
      text-align: center;
      margin-top: 20px;
      color: #868686;
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="header">
      <h1>Email Verification</h1>
    </div>
    <div class="message" id="message"></div>
    <div id="verification-status">
      @if ($status === 'verification-link-sent')
        <p>A new verification link has been sent to your email address.</p>
      @elseif ($status === 'email-verified')
        <p>Your email address has been successfully verified.</p>
      @elseif ($status === 'already-verified')
        <p>Your email address is already verified.</p>
      @else
        <p>Your email address could not be verified. Please try again.</p>
        <form method="POST" action="{{ route('verification.resend', ['id' => $id]) }}" id="resend-verification-form">
          @csrf
          <input type="hidden" name="status" value="verification-link-sent">
          <button type="submit" class="button">Resend Verification Email</button>
        </form>
      @endif
    </div>
    </div>
  </div>

  <script>
    document.getElementById('resend-verification-form').addEventListener('submit', function(event) {
      event.preventDefault();
      var form = this;
      var formData = new FormData(form);

      fetch(form.action, {
        method: form.method,
        body: formData,
        headers: {
          'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value
        }
      })
      .then(response => response.json())
      .then(({data}) => {
        if (data.message === 'Email verification link sent') {
          document.getElementById('message').innerHTML = '<p>A new verification link has been sent to your email address.</p>';
          document.getElementById('verification-status').innerHTML = '';
        } else {
          document.getElementById('message').innerHTML = '<p>There was an error sending the verification link. Please try again.</p>';
        }
      })
      .catch(error => {
        document.getElementById('message').innerHTML = '<p>There was an error sending the verification link. Please try again</p>';
      });
    });
  </script>
</body>
</html>
