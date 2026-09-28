document.addEventListener('DOMContentLoaded', function () {
    var clock = document.getElementById('t8LiveClock');
    var locationEl = document.getElementById('t8LiveLocation');
    var weatherEl = document.getElementById('t8LiveWeather');
    if (!clock || !locationEl || !weatherEl) return;

    function updateClock() {
        clock.textContent = new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', hour: 'numeric', minute: '2-digit', hour12: true }).format(new Date());
    }
    updateClock();
    window.setInterval(updateClock, 1000);

    var fallback = { latitude: 14.5995, longitude: 120.9842, label: 'Manila, Philippines' };
    var activePlace = fallback;
    function weatherLabel(code) {
        if (code === 0) return ['fa-sun', 'Clear'];
        if (code <= 3) return ['fa-cloud-sun', 'Partly Cloudy'];
        if (code <= 48) return ['fa-smog', 'Foggy'];
        if (code <= 67) return ['fa-cloud-rain', 'Rainy'];
        if (code <= 77) return ['fa-snowflake', 'Snow'];
        if (code <= 82) return ['fa-cloud-showers-heavy', 'Rain Showers'];
        return ['fa-cloud-bolt', 'Thunderstorms'];
    }
    function loadWeather(place) {
        weatherEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i><span>Weather loading...</span>';
        fetch('https://api.open-meteo.com/v1/forecast?latitude=' + encodeURIComponent(place.latitude) + '&longitude=' + encodeURIComponent(place.longitude) + '&current=temperature_2m,weather_code&temperature_unit=celsius', { cache: 'no-store' })
            .then(function (response) { if (!response.ok) throw new Error('Weather unavailable'); return response.json(); })
            .then(function (data) {
                var current = data.current, detail = weatherLabel(current.weather_code);
                weatherEl.innerHTML = '<i class="fa-solid ' + detail[0] + '"></i><span>' + Math.round(current.temperature_2m) + '°C ' + detail[1] + '</span>';
            })
            .catch(function () { weatherEl.innerHTML = '<i class="fa-solid fa-cloud"></i><span>Weather unavailable</span>'; });
    }
    function reverseLocation(place) {
        fetch('https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=' + encodeURIComponent(place.latitude) + '&lon=' + encodeURIComponent(place.longitude), { headers: { 'Accept': 'application/json' } })
            .then(function (response) { if (!response.ok) throw new Error('Location unavailable'); return response.json(); })
            .then(function (data) {
                var address = data.address || {}, city = address.city || address.town || address.village || address.municipality;
                if (city && address.country) locationEl.textContent = city + ', ' + address.country;
            })
            .catch(function () {});
    }
    function setPlace(place, detectName) {
        activePlace = place;
        locationEl.textContent = place.label;
        loadWeather(place);
        if (detectName) reverseLocation(place);
    }
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function (position) {
            setPlace({ latitude: position.coords.latitude, longitude: position.coords.longitude, label: 'Current location' }, true);
        }, function () { setPlace(fallback, false); }, { enableHighAccuracy: false, maximumAge: 1800000, timeout: 8000 });
    } else {
        setPlace(fallback, false);
    }
    window.setInterval(function () { loadWeather(activePlace); }, 1800000);
});
