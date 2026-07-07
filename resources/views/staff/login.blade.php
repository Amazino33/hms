<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Staff Login</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-gray-900 flex items-center justify-center p-6">
    <div class="w-full max-w-sm bg-white rounded-2xl shadow-xl p-8">
        <h1 class="text-xl font-bold text-gray-900 mb-1">Staff Login</h1>
        <p class="text-sm text-gray-500 mb-6">One-time login on this phone. Afterwards, unlock with your PIN.</p>

        @if ($errors->any())
            <div class="mb-4 text-sm text-red-600 font-medium">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('staff.login.submit') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Email</label>
                <input type="email" name="email" value="{{ old('email') }}" class="w-full px-4 py-3 border border-gray-300 rounded-lg text-lg" required>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Password</label>
                <input type="password" name="password" class="w-full px-4 py-3 border border-gray-300 rounded-lg text-lg" required>
            </div>
            <button type="submit" class="w-full py-3 bg-primary-600 text-white font-bold rounded-lg text-lg">Log In</button>
        </form>
    </div>
</body>
</html>
