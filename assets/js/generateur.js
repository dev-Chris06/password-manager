(() => {
    const button = document.getElementById('generer-mdp');
    const input = document.getElementById('mot_de_passe');

    if (!button || !input || !window.crypto) {
        return;
    }

    const groups = [
        'ABCDEFGHJKLMNPQRSTUVWXYZ',
        'abcdefghijkmnopqrstuvwxyz',
        '23456789',
        '!@#$%&*?-_+='
    ];
    const all = groups.join('');

    const randomIndex = (length) => {
        if (!Number.isFinite(length) || length <= 0) {
            throw new Error("Longueur invalide.");
        }
        const buffer = new Uint32Array(1);
        const range = Math.floor(length);
        const limit = 0xFFFFFFFF - (0xFFFFFFFF % range);
        while (true) {
            window.crypto.getRandomValues(buffer);
            if (buffer[0] < limit) {
                return buffer[0] % range;
            }
        }
    };

    const shuffle = (chars) => {
        for (let index = chars.length - 1; index > 0; index -= 1) {
            const swapIndex = randomIndex(index + 1);
            [chars[index], chars[swapIndex]] = [chars[swapIndex], chars[index]];
        }

        return chars;
    };

    button.addEventListener('click', () => {
        const chars = groups.map((group) => group[randomIndex(group.length)]);

        while (chars.length < 20) {
            chars.push(all[randomIndex(all.length)]);
        }

        input.value = shuffle(chars).join('');
        input.type = 'text';
        input.focus();
        input.select();
    });
})();
