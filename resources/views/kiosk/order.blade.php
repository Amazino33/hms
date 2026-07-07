@extends('layouts.kiosk')

@section('title', 'Order')

@section('content')
    <livewire:kiosk-order-wrapper :table="$table" />
@endsection
