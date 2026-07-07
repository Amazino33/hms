<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register This Device</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-gray-900 flex items-center justify-center p-6">
    <div class="w-full max-w-sm bg-white rounded-2xl shadow-xl p-8">
        <h1 class="text-xl font-bold text-gray-900 mb-1">Register This Device</h1>
        <p class="text-sm text-gray-500 mb-6">Enter the one-time code shown by your admin.</p>

        @if ($errors->any())
            <div class="mb-4 text-sm text-red-600 font-medium">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('kiosk.register.submit') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Device Name</label>
                <input type="text" name="device_name" value="{{ old('device_name') }}" placeholder="e.g. Bar Kiosk 1"
                    class="w-full px-4 py-3 border border-gray-300 rounded-lg text-lg" required>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Registration Code</label>
                <input type="text" name="code" placeholder="ABCD1234" autocapitalize="characters"
                    class="w-full px-4 py-3 border border-gray-300 rounded-lg text-lg uppercase tracking-widest" required>
            </div>
            <button type="submit" class="w-full py-3 bg-primary-600 text-white font-bold rounded-lg text-lg">
                Register Device
            </button>
        </form>
    </div>
</body>
</html>
