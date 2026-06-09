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
        const buffer = new Uint32Array(1);
        window.crypto.getRandomValues(buffer);
        return buffer[0] % length;
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
