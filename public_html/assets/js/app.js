(() => {
    const meta = window.AQV_PRODUCTS || {};
    const cart = {};
    let reservation = window.AQV_RESERVATION || null;
    let reservationValidated = !!(reservation && reservation.reservation_code);
    let currentStep = Number(window.AQV_INITIAL_STEP ?? (reservationValidated ? 1 : 0));
    let maxUnlockedStep = reservationValidated ? Math.max(1, currentStep) : 0;
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

    function ensureAppUi() {
        if (!document.getElementById('aqv-app-modal')) {
            document.body.insertAdjacentHTML('beforeend', `
                <div class="app-modal-backdrop" id="aqv-app-modal" hidden aria-hidden="true">
                    <div class="app-modal" role="dialog" aria-modal="true" aria-labelledby="aqv-modal-title">
                        <div class="app-modal-icon" id="aqv-modal-icon">!</div>
                        <div class="app-modal-content">
                            <div class="app-modal-header">
                                <h3 id="aqv-modal-title">Atenção</h3>
                                <button class="app-modal-close" type="button" data-modal-close aria-label="Fechar">×</button>
                            </div>
                            <div class="app-modal-body" id="aqv-modal-body"></div>
                            <div class="app-modal-actions">
                                <button class="btn btn-primary" type="button" data-modal-close>Entendi</button>
                            </div>
                        </div>
                    </div>
                </div>
            `);
        }

        if (!document.getElementById('mobile-cart-bar')) {
            document.body.insertAdjacentHTML('beforeend', `
                <div class="mobile-cart-bar" id="mobile-cart-bar" hidden>
                    <div class="mobile-cart-info">
                        <span id="mobile-cart-label">Seu pedido</span>
                        <strong id="mobile-cart-total">R$ 0,00</strong>
                    </div>
                    <button class="mobile-cart-next" id="mobile-cart-next" type="button">
                        Continuar
                        <span aria-hidden="true">→</span>
                    </button>
                </div>
            `);
        }

        const modal = document.getElementById('aqv-app-modal');
        modal?.querySelectorAll('[data-modal-close]').forEach(button => {
            button.addEventListener('click', closeAppModal);
        });
        modal?.addEventListener('click', event => {
            if (event.target === modal) closeAppModal();
        });

        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && modal && !modal.hidden) closeAppModal();
        });

        document.getElementById('mobile-cart-next')?.addEventListener('click', continueFromProducts);
    }

    function openAppModal(title, message, type = 'warning') {
        ensureAppUi();
        const modal = document.getElementById('aqv-app-modal');
        const titleEl = document.getElementById('aqv-modal-title');
        const bodyEl = document.getElementById('aqv-modal-body');
        const iconEl = document.getElementById('aqv-modal-icon');

        if (!modal || !titleEl || !bodyEl || !iconEl) return;

        titleEl.textContent = title;
        bodyEl.innerHTML = message;
        iconEl.textContent = type === 'error' ? '!' : type === 'success' ? '✓' : 'i';
        iconEl.dataset.type = type;

        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');

        window.setTimeout(() => {
            modal.querySelector('[data-modal-close]')?.focus();
        }, 30);
    }

    function closeAppModal() {
        const modal = document.getElementById('aqv-app-modal');
        if (!modal) return;
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
    }


    function displayDate(value) {
        const raw = String(value || '').trim();
        if (!raw) return '—';
        const datePart = raw.slice(0, 10);
        const match = datePart.match(/^(\d{4})-(\d{2})-(\d{2})$/);
        return match ? match[3] + '/' + match[2] + '/' + match[1] : raw;
    }

    function updateReservationCard(data) {
        const result = document.getElementById('reservation-result');
        if (!result) return;

        document.getElementById('reservation-result-name').textContent = data.guest_name || 'Hóspede da reserva';
        document.getElementById('reservation-result-code').textContent = data.reservation_code || '—';
        document.getElementById('reservation-result-checkin').textContent = displayDate(data.checkin_date);
        document.getElementById('reservation-result-uh').textContent = data.uh || '—';

        const guests = [];
        if (data.adults) guests.push(data.adults + ' adulto(s)');
        if (data.children) guests.push(data.children + ' criança(s)');
        document.getElementById('reservation-result-guests').textContent = guests.join(' · ') || '—';

        result.hidden = false;
        result.classList.add('is-visible');
    }

    function setReservationLoading(loading) {
        const button = document.getElementById('reservation-search-button');
        const loadingEl = document.getElementById('reservation-loading');
        if (button) {
            button.disabled = loading;
            button.textContent = loading ? 'Consultando...' : 'Procurar reserva';
        }
        if (loadingEl) loadingEl.hidden = !loading;
    }

    function resetPurchaseForReservation() {
        Object.keys(cart).forEach(key => delete cart[key]);
        document.querySelectorAll('[data-product-id]').forEach(input => {
            input.value = 0;
            input.closest('.product-card')?.classList.remove('is-selected');
        });

        visitorsSignature = '';
        const visitors = document.getElementById('visitors');
        if (visitors) visitors.innerHTML = '';

        document.querySelectorAll('#checkout-form input[type="checkbox"]').forEach(input => {
            input.checked = false;
        });

        const cartInput = document.getElementById('cart-json');
        if (cartInput) cartInput.value = '{}';

        maxUnlockedStep = reservationValidated ? 1 : 0;
        updateStepOneSummary();
        updateMobileCart();
        updateHeaderCart();
        updateProgress(currentStep);
    }

    async function lookupReservation() {
        const input = document.getElementById('reservation-code');
        const code = input?.value.trim() || '';

        if (!code) {
            openAppModal('Informe sua reserva', 'Digite o <strong>número da reserva</strong> para consultar o Expresso.', 'warning');
            input?.focus();
            return;
        }

        setReservationLoading(true);

        try {
            const response = await fetch(window.AQV_RESERVATION_ENDPOINT || 'reservation.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.AQV_CSRF || ''
                },
                body: JSON.stringify({
                    action: 'lookup',
                    reservation_code: code,
                    _csrf: window.AQV_CSRF || ''
                })
            });

            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.ok || !data.reservation) {
                reservation = null;
                reservationValidated = false;
                maxUnlockedStep = 0;
                updateProgress(0);
                document.getElementById('reservation-result')?.setAttribute('hidden', '');
                openAppModal(
                    'Reserva não localizada',
                    escapeHtml(data.message || 'Não foi possível validar esta reserva no Expresso. Confira o número e tente novamente.'),
                    'error'
                );
                return;
            }

            reservation = data.reservation;
            reservationValidated = true;
            currentStep = 0;
            resetPurchaseForReservation();
            unlockStep(1);
            updateReservationCard(reservation);

            openAppModal(
                'Reserva encontrada',
                '<strong>' + escapeHtml(reservation.guest_name || 'Reserva validada') + '</strong><br>A compra de ingressos foi liberada para esta reserva.',
                'success'
            );
        } catch (error) {
            reservation = null;
            reservationValidated = false;
            maxUnlockedStep = 0;
            updateProgress(0);
            openAppModal(
                'Falha na consulta',
                'Não foi possível consultar o Expresso agora. Verifique a conexão e tente novamente.',
                'error'
            );
        } finally {
            setReservationLoading(false);
        }
    }

    async function clearReservation() {
        try {
            await fetch(window.AQV_RESERVATION_ENDPOINT || 'reservation.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.AQV_CSRF || ''
                },
                body: JSON.stringify({
                    action: 'clear',
                    _csrf: window.AQV_CSRF || ''
                })
            });
        } catch (_) {
            // A limpeza local continua; o servidor também bloqueará checkout sem reserva validada.
        }

        reservation = null;
        reservationValidated = false;
        currentStep = 0;
        maxUnlockedStep = 0;
        resetPurchaseForReservation();

        const input = document.getElementById('reservation-code');
        if (input) input.value = '';

        const result = document.getElementById('reservation-result');
        if (result) {
            result.hidden = true;
            result.classList.remove('is-visible');
        }

        goToStep(0);
        input?.focus();
    }

    function readCart() {
        document.querySelectorAll('[data-product-id]').forEach(input => {
            const id = input.dataset.productId;
            const quantity = Math.max(0, Math.min(20, parseInt(input.value || '0', 10)));
            input.value = quantity;
            if (quantity > 0) cart[id] = quantity;
            else delete cart[id];

            const card = input.closest('.product-card');
            card?.classList.toggle('is-selected', quantity > 0);
        });

        const hidden = document.getElementById('cart-json');
        if (hidden) hidden.value = JSON.stringify(cart);

        const signature = cartSignature();
        if (visitorsSignature && signature !== visitorsSignature && maxUnlockedStep > 1) {
            maxUnlockedStep = reservationValidated ? 1 : 0;
            visitorsSignature = '';
        }

        updateStepOneSummary();
        updateMobileCart();
        updateHeaderCart();
        updateProgress(currentStep);
    }

    function ticketCount() {
        return Object.entries(cart).reduce((total, [id, quantity]) => {
            const product = meta[id];
            return total + (product && product.requires_visitor ? quantity : 0);
        }, 0);
    }

    function itemCount() {
        return Object.values(cart).reduce((total, quantity) => total + quantity, 0);
    }

    function cartTotal() {
        return Object.entries(cart).reduce((total, [id, quantity]) => {
            const product = meta[id];
            return total + (product ? product.price * quantity : 0);
        }, 0);
    }

    function cartSignature() {
        return JSON.stringify(
            Object.entries(cart).sort(([a], [b]) => Number(a) - Number(b))
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

    function updateHeaderCart() {
        const button = document.getElementById('start-purchase');
        if (!button) return;

        const count = itemCount();
        if (window.matchMedia('(max-width: 680px)').matches) {
            button.innerHTML = count > 0
                ? '<span class="header-cart-dot">' + count + '</span><span>Carrinho</span>'
                : '<span class="header-cart-icon" aria-hidden="true">⌁</span><span>Ingressos</span>';
            button.classList.add('mobile-header-cart');
        } else {
            button.textContent = 'Comprar ingressos';
            button.classList.remove('mobile-header-cart');
        }
    }

    function updateMobileCart() {
        const bar = document.getElementById('mobile-cart-bar');
        const label = document.getElementById('mobile-cart-label');
        const total = document.getElementById('mobile-cart-total');
        if (!bar || !label || !total) return;

        const count = itemCount();
        const visible = currentStep === 1 && count > 0 && window.matchMedia('(max-width: 680px)').matches;

        bar.hidden = !visible;
        document.body.classList.toggle('has-mobile-cart', visible);

        if (!visible) return;

        const people = ticketCount();
        const itemText = count === 1 ? '1 item' : count + ' itens';
        const peopleText = people === 1 ? '1 visitante' : people + ' visitantes';
        label.textContent = itemText + (people > 0 ? ' · ' + peopleText : '');
        total.textContent = brl(cartTotal());
    }

    function updateProgress(step) {
        document.querySelectorAll('[data-progress-step]').forEach(item => {
            const number = Number(item.dataset.progressStep);
            const unlocked = number <= maxUnlockedStep;

            item.classList.toggle('is-active', number === step);
            item.classList.toggle('is-done', number < step && unlocked);
            item.classList.toggle('is-unlocked', unlocked);
            item.classList.toggle('is-locked', !unlocked);

            if (item.matches('[data-flow-step]')) {
                item.setAttribute('aria-disabled', unlocked ? 'false' : 'true');
                item.tabIndex = unlocked ? 0 : -1;
            }
        });
    }

    function unlockStep(step) {
        maxUnlockedStep = Math.max(maxUnlockedStep, step);
        updateProgress(currentStep);
    }

    function goToStep(step) {
        if (step > maxUnlockedStep) return;

        currentStep = step;
        document.querySelectorAll('.wizard-stage[data-step]').forEach(section => {
            section.classList.toggle('is-active', Number(section.dataset.step) === step);
        });

        updateProgress(step);
        updateMobileCart();

        const target = document.getElementById('compra');
        if (target) {
            const offset = 82;
            const y = target.getBoundingClientRect().top + window.scrollY - offset;
            window.scrollTo({ top: y, behavior: 'smooth' });
        }
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

                const reservationGuestName = index === 0 ? String(reservation?.guest_name || '').trim() : '';
                const reservationNameParts = reservationGuestName.split(/\s+/).filter(Boolean);
                const reservationFirstName = reservationNameParts.shift() || '';
                const reservationLastName = reservationNameParts.join(' ');
                const reservationCpf = index === 0 ? String(reservation?.guest_cpf || '').trim() : '';

                html += `
                <article class="card person-card ${index === 0 ? 'is-open' : ''}" data-person-index="${index}">
                    <button class="person-card-head" type="button" data-person-toggle="${index}" aria-expanded="${index === 0 ? 'true' : 'false'}">
                        <div class="person-head-left">
                            <span class="person-status-dot" aria-hidden="true"></span>
                            <div>
                                <span class="person-label">Pessoa ${index + 1}</span>
                                <h3>Dados do visitante</h3>
                            </div>
                        </div>
                        <div class="person-product">
                            <strong>${escapeHtml(product.name)}</strong>
                            <span>${escapeHtml(duration)}</span>
                        </div>
                        <span class="person-chevron" aria-hidden="true">⌄</span>
                    </button>

                    <div class="person-card-body">
                        <input type="hidden" name="visitors[${index}][product_id]" value="${productId}">

                        <div class="form-grid">
                            <div class="form-group">
                                <label>Nome</label>
                                <input name="visitors[${index}][first_name]" value="${escapeHtml(reservationFirstName)}" required autocomplete="given-name" data-field-label="nome">
                            </div>
                            <div class="form-group">
                                <label>Sobrenome</label>
                                <input name="visitors[${index}][last_name]" value="${escapeHtml(reservationLastName)}" required autocomplete="family-name" data-field-label="sobrenome">
                            </div>

                            <div class="form-group">
                                <label>E-mail</label>
                                <input type="email" name="visitors[${index}][email]" required autocomplete="email" data-field-label="e-mail">
                            </div>
                            <div class="form-group">
                                <label>Telefone</label>
                                <input name="visitors[${index}][phone]" required autocomplete="tel" inputmode="tel" data-field-label="telefone">
                            </div>

                            <div class="form-group">
                                <label>Data de entrada</label>
                                <input type="date" min="${new Date().toISOString().slice(0, 10)}" name="visitors[${index}][entry_date]" required data-field-label="data de entrada">
                            </div>
                            <div class="form-group">
                                <label>Sexo</label>
                                <select name="visitors[${index}][sex]" required data-field-label="sexo">
                                    <option value="">Selecione</option>
                                    <option value="feminino">Feminino</option>
                                    <option value="masculino">Masculino</option>
                                    <option value="outro">Outro</option>
                                    <option value="nao_informado">Prefiro não informar</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Documento</label>
                                <select name="visitors[${index}][document_type]" required data-field-label="tipo de documento">
                                    <option value="CPF">CPF</option>
                                    <option value="RG">RG</option>
                                    <option value="CNH">Carteira de motorista</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Número do documento</label>
                                <input name="visitors[${index}][document_number]" value="${escapeHtml(reservationCpf)}" required autocomplete="off" data-field-label="número do documento">
                            </div>

                            <div class="form-group full photo-field">
                                <label>Foto para identificação na catraca</label>
                                <div class="photo-control">
                                    <div class="photo-preview-shell">
                                        <img class="photo-preview" id="photo-${index}" alt="Prévia da foto da Pessoa ${index + 1}">
                                        <span class="photo-placeholder" id="photo-placeholder-${index}" aria-hidden="true">
                                            <span class="photo-placeholder-icon">◎</span>
                                            <small>Sem foto</small>
                                        </span>
                                    </div>

                                    <div class="photo-actions">
                                        <input class="photo-input" id="photo-input-${index}" data-preview="photo-${index}" data-placeholder="photo-placeholder-${index}" type="file" name="photos[${index}]" accept="image/jpeg,image/png,image/webp" required data-field-label="foto" hidden>
                                        <button class="photo-action photo-action-primary" type="button" data-photo-camera="${index}">
                                            <span aria-hidden="true">◉</span> Tirar foto
                                        </button>
                                        <button class="photo-action" type="button" data-photo-gallery="${index}">
                                            <span aria-hidden="true">▧</span> Galeria
                                        </button>
                                        <small>Foto frontal, nítida e com apenas esta pessoa.</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="person-mobile-done">
                            <button type="button" class="btn btn-outline" data-person-done="${index}">
                                Salvar pessoa e continuar
                            </button>
                        </div>
                    </div>
                </article>`;
            }
        });

        wrap.innerHTML = html;
        bindPersonAccordions();
        bindPhotoControls();
        bindDatePropagation();
        bindFieldState();
    }

    function bindPersonAccordions() {
        document.querySelectorAll('[data-person-toggle]').forEach(button => {
            button.addEventListener('click', () => {
                const card = button.closest('.person-card');
                if (!card) return;

                const willOpen = !card.classList.contains('is-open');
                if (willOpen && window.matchMedia('(max-width: 680px)').matches) {
                    document.querySelectorAll('.person-card.is-open').forEach(other => {
                        if (other !== card) {
                            other.classList.remove('is-open');
                            other.querySelector('[data-person-toggle]')?.setAttribute('aria-expanded', 'false');
                        }
                    });
                }

                card.classList.toggle('is-open', willOpen);
                button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            });
        });

        document.querySelectorAll('[data-person-done]').forEach(button => {
            button.addEventListener('click', () => {
                const card = button.closest('.person-card');
                if (!card) return;

                const invalid = findInvalidControl(card);
                if (invalid) {
                    showFieldError(invalid, card);
                    return;
                }

                card.classList.add('is-complete');
                card.classList.remove('is-open');
                card.querySelector('[data-person-toggle]')?.setAttribute('aria-expanded', 'false');

                const next = card.nextElementSibling;
                if (next?.classList.contains('person-card')) {
                    next.classList.add('is-open');
                    next.querySelector('[data-person-toggle]')?.setAttribute('aria-expanded', 'true');
                    next.scrollIntoView({ behavior: 'smooth', block: 'start' });
                } else {
                    openAppModal('Cadastro conferido', 'Os dados dos visitantes estão preenchidos. Você pode continuar para as regras de utilização.', 'success');
                }
            });
        });
    }

    function bindPhotoControls() {
        document.querySelectorAll('[data-photo-camera]').forEach(button => {
            button.addEventListener('click', () => {
                const input = document.getElementById('photo-input-' + button.dataset.photoCamera);
                if (!input) return;
                input.setAttribute('capture', 'user');
                input.click();
            });
        });

        document.querySelectorAll('[data-photo-gallery]').forEach(button => {
            button.addEventListener('click', () => {
                const input = document.getElementById('photo-input-' + button.dataset.photoGallery);
                if (!input) return;
                input.removeAttribute('capture');
                input.click();
            });
        });

        document.querySelectorAll('.photo-input').forEach(input => {
            input.addEventListener('change', () => {
                const image = document.getElementById(input.dataset.preview);
                const placeholder = document.getElementById(input.dataset.placeholder);
                const file = input.files && input.files[0];

                if (!image || !file) return;

                if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
                    input.value = '';
                    openAppModal('Foto não suportada', 'Escolha uma imagem JPG, PNG ou WEBP.', 'error');
                    return;
                }

                image.src = URL.createObjectURL(file);
                image.style.display = 'block';
                if (placeholder) placeholder.hidden = true;
                input.classList.remove('is-invalid');

                updatePersonCompletion(input.closest('.person-card'));
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

    function bindFieldState() {
        document.querySelectorAll('.person-card input, .person-card select').forEach(control => {
            const eventName = control.matches('select,input[type="date"],input[type="file"]') ? 'change' : 'input';
            control.addEventListener(eventName, () => {
                if (control.checkValidity()) control.classList.remove('is-invalid');
                updatePersonCompletion(control.closest('.person-card'));
            });
        });
    }

    function updatePersonCompletion(card) {
        if (!card) return;
        const invalid = findInvalidControl(card);
        card.classList.toggle('is-complete', !invalid);
    }

    function findInvalidControl(scope) {
        const controls = [...scope.querySelectorAll('input:not([type="hidden"]), select, textarea')];
        return controls.find(control => !control.checkValidity()) || null;
    }

    function fieldLabel(control) {
        return control.dataset.fieldLabel
            || control.closest('.form-group')?.querySelector('label')?.textContent?.trim()
            || 'campo obrigatório';
    }

    function showFieldError(control, card = null) {
        if (!control) return;
        control.classList.add('is-invalid');

        const targetCard = card || control.closest('.person-card');
        if (targetCard) {
            targetCard.classList.add('is-open');
            targetCard.querySelector('[data-person-toggle]')?.setAttribute('aria-expanded', 'true');
        }

        const label = escapeHtml(fieldLabel(control));
        let detail = 'Preencha o campo <strong>' + label + '</strong> para continuar.';

        if (control.validity?.typeMismatch) {
            detail = 'Confira o formato do campo <strong>' + label + '</strong>.';
        } else if (control.type === 'file') {
            detail = 'Adicione a <strong>foto do visitante</strong> antes de continuar.';
        } else if (control.type === 'checkbox') {
            detail = 'É necessário confirmar <strong>' + label + '</strong> para continuar.';
        }

        openAppModal('Falta uma informação', detail, 'warning');

        window.setTimeout(() => {
            if (control.type !== 'file' && control.type !== 'checkbox') control.focus({ preventScroll: true });
            control.closest('.form-group, .accept-line, .person-card')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 180);
    }

    function validateStep(step) {
        const section = stepEl(step);
        if (!section) return true;

        const invalid = findInvalidControl(section);
        if (!invalid) return true;

        showFieldError(invalid);
        return false;
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

    function continueFromProducts() {
        readCart();

        if (ticketCount() < 1) {
            openAppModal(
                'Escolha um ingresso',
                'Selecione <strong>ao menos um ingresso</strong> para cadastrar os visitantes. Adicionais como locker podem ser incluídos junto com o ingresso.',
                'warning'
            );
            return;
        }

        buildVisitors();
        unlockStep(2);
        goToStep(2);
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

    document.getElementById('reservation-search-button')?.addEventListener('click', lookupReservation);
    document.getElementById('reservation-code')?.addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();
            lookupReservation();
        }
    });
    document.getElementById('reservation-change-button')?.addEventListener('click', clearReservation);
    document.getElementById('continue-step-0')?.addEventListener('click', () => {
        if (!reservationValidated) {
            openAppModal('Valide sua reserva', 'Localize uma reserva válida antes de acessar os ingressos.', 'warning');
            return;
        }
        unlockStep(1);
        goToStep(1);
    });

    document.getElementById('continue-step-1')?.addEventListener('click', continueFromProducts);

    document.getElementById('continue-step-2')?.addEventListener('click', () => {
        if (!validateStep(2)) return;
        syncBuyerContact();
        unlockStep(3);
        goToStep(3);
    });

    document.getElementById('continue-step-3')?.addEventListener('click', () => {
        if (!validateStep(3)) return;
        buildReview();
        unlockStep(4);
        goToStep(4);
    });

    document.querySelectorAll('[data-flow-step]').forEach(button => {
        button.addEventListener('click', () => {
            const target = Number(button.dataset.flowStep);
            if (!Number.isInteger(target) || target < 0 || target === currentStep) return;

            if (target > maxUnlockedStep) {
                openAppModal(
                    'Etapa ainda não liberada',
                    'Conclua a etapa atual para liberar <strong>' + escapeHtml(button.querySelector('strong')?.textContent || 'a próxima etapa') + '</strong>.',
                    'warning'
                );
                return;
            }

            if (target > currentStep) {
                for (let step = currentStep; step < target; step += 1) {
                    if (step === 0 && !reservationValidated) {
                        openAppModal('Valide sua reserva', 'A etapa de ingressos só é liberada depois de localizar uma reserva válida.', 'warning');
                        return;
                    }

                    if (step === 1) {
                        readCart();
                        if (ticketCount() < 1) {
                            openAppModal('Escolha um ingresso', 'Selecione ao menos um ingresso para continuar.', 'warning');
                            return;
                        }
                        buildVisitors();
                    }

                    if (step === 2 && !validateStep(2)) return;
                    if (step === 3 && !validateStep(3)) return;
                }

                if (target === 4) buildReview();
            }

            goToStep(target);
        });
    });

    document.querySelectorAll('[data-back]').forEach(button => {
        button.addEventListener('click', () => goToStep(Number(button.dataset.back)));
    });

    document.getElementById('checkout-form')?.addEventListener('submit', event => {
        readCart();

        if (!reservationValidated) {
            event.preventDefault();
            goToStep(0);
            openAppModal('Reserva obrigatória', 'Antes de concluir a compra, localize e valide sua reserva no <strong>Passo 0</strong>.', 'warning');
            return;
        }

        if (ticketCount() < 1) {
            event.preventDefault();
            goToStep(1);
            openAppModal('Escolha um ingresso', 'Adicione ao menos um ingresso antes de finalizar o pedido.', 'warning');
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
            openAppModal(
                'Contato principal incompleto',
                'Informe <strong>e-mail e telefone da Pessoa 1</strong>. Esses dados serão usados como contato principal do pedido.',
                'warning'
            );
        }
    });

    window.addEventListener('resize', () => {
        updateMobileCart();
        updateHeaderCart();
    });

    ensureAppUi();
    if (reservationValidated && reservation) {
        updateReservationCard(reservation);
        maxUnlockedStep = Math.max(maxUnlockedStep, 1);
    }
    readCart();
    updateProgress(currentStep);
})();
