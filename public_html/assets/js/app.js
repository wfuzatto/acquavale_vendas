(() => {
    const meta = window.AQV_PRODUCTS || {};
    const cart = {};
    let currentStep = 1;
    let visitorsSignature = '';

    const brl = value => new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL'
    }).format(value);

    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    }[char]));

    const stepEl = step => document.querySelector('.wizard-stage[data-step="' + step + '"]');

    function readCart() {
        document.querySelectorAll('[data-product-id]').forEach(input => {
            const id = input.dataset.productId;
            const quantity = Math.max(0, Math.min(20, parseInt(input.value || '0', 10)));
            if (quantity > 0) cart[id] = quantity;
            else delete cart[id];
        });

        const hidden = document.getElementById('cart-json');
        if (hidden) hidden.value = JSON.stringify(cart);
        updateStepOneSummary();
    }

    function ticketCount() {
        return Object.entries(cart).reduce((total, [id, quantity]) => {
            const product = meta[id];
            return total + (product && product.requires_visitor ? quantity : 0);
        }, 0);
    }

    function cartTotal() {
        return Object.entries(cart).reduce((total, [id, quantity]) => {
            const product = meta[id];
            return total + (product ? product.price * quantity : 0);
        }, 0);
    }

    function cartSignature() {
        return JSON.stringify(
            Object.entries(cart)
                .sort(([a], [b]) => Number(a) - Number(b))
        );
    }

    function updateStepOneSummary() {
        const count = ticketCount();
        const countEl = document.getElementById('step1-count');
        const totalEl = document.getElementById('step1-total');

        if (countEl) {
            countEl.textContent = count === 0
                ? 'Nenhum ingresso selecionado'
                : count + (count === 1 ? ' pessoa será cadastrada' : ' pessoas serão cadastradas');
        }

        if (totalEl) totalEl.textContent = brl(cartTotal());
    }

    function updateProgress(step) {
        document.querySelectorAll('[data-progress-step]').forEach(item => {
            const number = Number(item.dataset.progressStep);
            item.classList.toggle('is-active', number === step);
            item.classList.toggle('is-done', number < step);
        });
    }

    function goToStep(step) {
        currentStep = step;
        document.querySelectorAll('.wizard-stage[data-step]').forEach(section => {
            section.classList.toggle('is-active', Number(section.dataset.step) === step);
        });
        updateProgress(step);
        document.getElementById('compra')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function buildVisitors() {
        const wrap = document.getElementById('visitors');
        if (!wrap) return;

        const signature = cartSignature();
        if (signature === visitorsSignature && wrap.children.length > 0) return;

        visitorsSignature = signature;
        let personIndex = 0;
        let html = '';

        Object.entries(cart).forEach(([productId, quantity]) => {
            const product = meta[productId];
            if (!product || !product.requires_visitor) return;

            for (let unit = 0; unit < quantity; unit += 1) {
                const index = personIndex++;
                const duration = product.duration_days === 1
                    ? '1 dia de acesso'
                    : product.duration_days + ' dias de acesso';

                html += `
                <article class="card person-card" data-person-index="${index}">
                    <div class="person-card-head">
                        <div>
                            <span class="person-label">Pessoa ${index + 1}</span>
                            <h3>Cadastro completo</h3>
                        </div>
                        <div class="person-product">
                            <strong>${escapeHtml(product.name)}</strong>
                            <span>${escapeHtml(duration)}</span>
                        </div>
                    </div>

                    <input type="hidden" name="visitors[${index}][product_id]" value="${productId}">

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Nome</label>
                            <input name="visitors[${index}][first_name]" required autocomplete="given-name">
                        </div>
                        <div class="form-group">
                            <label>Sobrenome</label>
                            <input name="visitors[${index}][last_name]" required autocomplete="family-name">
                        </div>

                        <div class="form-group">
                            <label>E-mail</label>
                            <input type="email" name="visitors[${index}][email]" required autocomplete="email">
                        </div>
                        <div class="form-group">
                            <label>Telefone</label>
                            <input name="visitors[${index}][phone]" required autocomplete="tel" inputmode="tel">
                        </div>

                        <div class="form-group">
                            <label>Data de entrada</label>
                            <input type="date" min="${new Date().toISOString().slice(0, 10)}" name="visitors[${index}][entry_date]" required>
                        </div>
                        <div class="form-group">
                            <label>Sexo</label>
                            <select name="visitors[${index}][sex]" required>
                                <option value="">Selecione</option>
                                <option value="feminino">Feminino</option>
                                <option value="masculino">Masculino</option>
                                <option value="outro">Outro</option>
                                <option value="nao_informado">Prefiro não informar</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Documento</label>
                            <select name="visitors[${index}][document_type]" required>
                                <option value="CPF">CPF</option>
                                <option value="RG">RG</option>
                                <option value="CNH">Carteira de motorista</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Número do documento</label>
                            <input name="visitors[${index}][document_number]" required autocomplete="off">
                        </div>

                        <div class="form-group full photo-field">
                            <label>Foto para identificação na catraca</label>
                            <div class="photo-control">
                                <label class="photo-picker">
                                    <input class="photo-input" data-preview="photo-${index}" type="file" name="photos[${index}]" accept="image/jpeg,image/png,image/webp" required>
                                    <span>📷 Tirar foto ou escolher da galeria</span>
                                </label>
                                <img class="photo-preview" id="photo-${index}" alt="Prévia da foto da Pessoa ${index + 1}">
                            </div>
                            <small>Use uma foto frontal, nítida, com apenas esta pessoa.</small>
                        </div>
                    </div>
                </article>`;
            }
        });

        wrap.innerHTML = html;
        bindPhotoPreviews();
        bindDatePropagation();
    }

    function bindPhotoPreviews() {
        document.querySelectorAll('.photo-input').forEach(input => {
            input.addEventListener('change', () => {
                const image = document.getElementById(input.dataset.preview);
                const file = input.files && input.files[0];
                if (!image || !file) return;
                image.src = URL.createObjectURL(file);
                image.style.display = 'block';
            });
        });
    }

    function bindDatePropagation() {
        const dates = [...document.querySelectorAll('input[name$="[entry_date]"]')];
        if (dates.length < 2) return;

        dates[0].addEventListener('change', () => {
            if (!dates[0].value) return;
            dates.slice(1).forEach(input => {
                if (!input.value) input.value = dates[0].value;
            });
        });
    }

    function validateStep(step) {
        const section = stepEl(step);
        if (!section) return true;

        const controls = [...section.querySelectorAll('input, select, textarea')];
        for (const control of controls) {
            if (!control.checkValidity()) {
                control.reportValidity();
                control.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return false;
            }
        }
        return true;
    }

    function syncBuyerContact() {
        const firstEmail = document.querySelector('input[name="visitors[0][email]"]');
        const firstPhone = document.querySelector('input[name="visitors[0][phone]"]');
        const buyerEmail = document.getElementById('buyer-email');
        const buyerPhone = document.getElementById('buyer-phone');

        if (buyerEmail) buyerEmail.value = firstEmail?.value.trim() || '';
        if (buyerPhone) buyerPhone.value = firstPhone?.value.trim() || '';
    }

    function formatDate(value) {
        if (!value) return '—';
        const [year, month, day] = value.split('-');
        return `${day}/${month}/${year}`;
    }

    function buildReview() {
        syncBuyerContact();

        const productsEl = document.getElementById('review-products');
        const peopleEl = document.getElementById('review-people');
        const totalEl = document.getElementById('review-total');

        if (productsEl) {
            let productsHtml = '';
            Object.entries(cart).forEach(([id, quantity]) => {
                const product = meta[id];
                if (!product) return;
                productsHtml += `
                    <div class="summary-row">
                        <span>${quantity}× ${escapeHtml(product.name)}</span>
                        <strong>${brl(product.price * quantity)}</strong>
                    </div>`;
            });
            productsEl.innerHTML = productsHtml;
        }

        if (peopleEl) {
            const cards = [...document.querySelectorAll('.person-card')];
            peopleEl.innerHTML = cards.map((card, index) => {
                const first = card.querySelector('input[name$="[first_name]"]')?.value || '';
                const last = card.querySelector('input[name$="[last_name]"]')?.value || '';
                const date = card.querySelector('input[name$="[entry_date]"]')?.value || '';
                const productId = card.querySelector('input[name$="[product_id]"]')?.value || '';
                const product = meta[productId];

                return `
                <div class="person-summary">
                    <div class="person-summary-number">${index + 1}</div>
                    <div>
                        <strong>${escapeHtml((first + ' ' + last).trim())}</strong>
                        <span>${escapeHtml(product?.name || '')} · entrada ${escapeHtml(formatDate(date))}</span>
                    </div>
                </div>`;
            }).join('');
        }

        if (totalEl) totalEl.textContent = brl(cartTotal());
    }

    document.querySelectorAll('[data-qty-action]').forEach(button => {
        button.addEventListener('click', () => {
            const input = document.querySelector('[data-product-id="' + button.dataset.product + '"]');
            if (!input) return;

            const delta = button.dataset.qtyAction === 'plus' ? 1 : -1;
            input.value = Math.max(0, Math.min(20, parseInt(input.value || '0', 10) + delta));
            readCart();
        });
    });

    document.querySelectorAll('[data-product-id]').forEach(input => {
        input.addEventListener('change', readCart);
        input.addEventListener('input', readCart);
    });

    document.getElementById('start-purchase')?.addEventListener('click', () => {
        document.getElementById('compra')?.scrollIntoView({ behavior: 'smooth' });
    });

    document.getElementById('continue-step-1')?.addEventListener('click', () => {
        readCart();
        if (ticketCount() < 1) {
            alert('Selecione ao menos um ingresso para continuar.');
            return;
        }
        buildVisitors();
        goToStep(2);
    });

    document.getElementById('continue-step-2')?.addEventListener('click', () => {
        if (!validateStep(2)) return;
        syncBuyerContact();
        goToStep(3);
    });

    document.getElementById('continue-step-3')?.addEventListener('click', () => {
        if (!validateStep(3)) return;
        buildReview();
        goToStep(4);
    });

    document.querySelectorAll('[data-back]').forEach(button => {
        button.addEventListener('click', () => goToStep(Number(button.dataset.back)));
    });

    document.getElementById('checkout-form')?.addEventListener('submit', event => {
        readCart();

        if (ticketCount() < 1) {
            event.preventDefault();
            goToStep(1);
            return;
        }

        if (!validateStep(2)) {
            event.preventDefault();
            goToStep(2);
            return;
        }

        if (!validateStep(3)) {
            event.preventDefault();
            goToStep(3);
            return;
        }

        syncBuyerContact();

        const buyerEmail = document.getElementById('buyer-email')?.value || '';
        const buyerPhone = document.getElementById('buyer-phone')?.value || '';
        if (!buyerEmail || !buyerPhone) {
            event.preventDefault();
            goToStep(2);
            alert('Informe e-mail e telefone da Pessoa 1.');
        }
    });

    readCart();
    updateProgress(currentStep);
})();
