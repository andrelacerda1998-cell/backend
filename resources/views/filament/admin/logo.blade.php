@if(str(request()->uri())->contains('login'))
<div class="flex w-full">
    <img alt="Logo" title="Logo" class="h-10 w-full" draggable="false" src="{{asset('images/Logo.png')}}">
</div>
@else
<div class="flex w-full">
    <img alt="Logo" title="Logo" class="h-10 w-full" draggable="false" src="{{asset('images/Logo-Dark.png')}}">
</div>
@endif
