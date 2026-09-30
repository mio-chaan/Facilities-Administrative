(() => {
    const layer = document.querySelector('.t8-auth-petals');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const mascot = document.querySelector('.t8-auth-mascot');
    const mascotArt = document.querySelector('.t8-auth-mascot-art');
    const card = document.querySelector('.t8-auth-card');
    const maxPetals = 32;

    const fitMascot = () => {
        if (!mascot || !mascotArt || !card) return;

        mascot.style.removeProperty('--t8-auth-mascot-width');
        let low = 0;
        let high = Number.parseFloat(getComputedStyle(mascot).width);
        const overlapsCard = () => {
            const artBounds = mascotArt.getBoundingClientRect();
            const cardBounds = card.getBoundingClientRect();
            const visibleBounds = {
                left: artBounds.left + artBounds.width * (83 / 703),
                right: artBounds.left + artBounds.width * (600 / 703),
                top: artBounds.top + artBounds.height * (19 / 652),
                bottom: artBounds.top + artBounds.height * (614 / 652),
            };
            const gap = 8;

            return visibleBounds.right + gap > cardBounds.left
                && visibleBounds.left - gap < cardBounds.right
                && visibleBounds.bottom + gap > cardBounds.top
                && visibleBounds.top - gap < cardBounds.bottom;
        };

        if (!overlapsCard()) return;

        for (let attempt = 0; attempt < 16; attempt += 1) {
            const width = (low + high) / 2;
            mascot.style.setProperty('--t8-auth-mascot-width', `${width}px`);
            if (overlapsCard()) high = width;
            else low = width;
        }

        mascot.style.setProperty('--t8-auth-mascot-width', `${Math.floor(low)}px`);
    };

    fitMascot();
    window.addEventListener('resize', fitMascot, { passive: true });
    const mascotFitObserver = new ResizeObserver(fitMascot);
    mascotFitObserver.observe(document.querySelector('.t8-auth-wrapper'));
    if (card) mascotFitObserver.observe(card);

    if (!layer || reducedMotion.matches) return;

    const randomBetween = (min, max) => Math.random() * (max - min) + min;

    const spawnPetal = () => {
        if (layer.childElementCount >= maxPetals) return;

        const petal = document.createElement('i');
        const fromCenter = Math.random() < 0.25;
        const fromLeft = !fromCenter && Math.random() < 0.5;
        const edgeWidth = Math.min(window.innerWidth * 0.2, 180);
        const x = fromCenter
            ? randomBetween(window.innerWidth * 0.35, window.innerWidth * 0.65)
            : fromLeft
                ? randomBetween(0, edgeWidth)
                : randomBetween(window.innerWidth - edgeWidth, window.innerWidth);
        const y = fromCenter ? randomBetween(16, 36) : randomBetween(6, 23);
        const sway = randomBetween(12, 34) * (Math.random() < 0.5 ? -1 : 1);
        const windDirection = fromCenter
            ? (Math.random() < 0.5 ? -1 : 1)
            : fromLeft ? 1 : -1;
        const windRange = fromCenter ? randomBetween(0.1, 0.28) : randomBetween(0.25, 0.5);
        const windDistance = window.innerWidth * windRange * windDirection;
        const rotation = randomBetween(300, 520) * (Math.random() < 0.5 ? -1 : 1);
        const swayOffsets = [sway, -sway * 0.7, sway * 0.55, -sway * 0.35];
        const stages = ['one', 'two', 'three', 'four'];

        petal.className = 't8-auth-petal';
        petal.dataset.origin = fromCenter ? 'center' : fromLeft ? 'left' : 'right';
        petal.style.setProperty('--petal-left', `${x}px`);
        petal.style.setProperty('--petal-top', `${y}vh`);
        petal.style.setProperty('--petal-size', `${randomBetween(12, 23)}px`);
        petal.style.setProperty('--petal-duration', `${randomBetween(9, 13)}s`);
        stages.forEach((stage, index) => {
            const progress = (index + 1) / stages.length;
            petal.style.setProperty(`--petal-x-${stage}`, `${windDistance * progress + swayOffsets[index]}px`);
            petal.style.setProperty(`--petal-rotation-${stage}`, `${rotation * progress}deg`);
        });
        layer.append(petal);
        petal.addEventListener('animationend', () => petal.remove(), { once: true });
    };

    for (let index = 0; index < 5; index += 1) {
        window.setTimeout(spawnPetal, index * 300);
    }
    window.setInterval(spawnPetal, 360);
})();