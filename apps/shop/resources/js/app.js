document.addEventListener('DOMContentLoaded', () => {
    document
        .querySelectorAll('[data-product-gallery]')
        .forEach((gallery) => {
            const mainImage = gallery.querySelector(
                '[data-gallery-main]',
            );

            const openButton = gallery.querySelector(
                '[data-gallery-open]',
            );

            const thumbnails = Array.from(
                gallery.querySelectorAll(
                    '[data-gallery-thumbnail]',
                ),
            );

            const modal = gallery.querySelector(
                '[data-gallery-modal]',
            );

            const modalImage = gallery.querySelector(
                '[data-gallery-modal-image]',
            );

            const closeButton = gallery.querySelector(
                '[data-gallery-close]',
            );

            const previousButton = gallery.querySelector(
                '[data-gallery-previous]',
            );

            const nextButton = gallery.querySelector(
                '[data-gallery-next]',
            );

            const currentCounter = gallery.querySelector(
                '[data-gallery-current]',
            );

            if (! mainImage || ! openButton || ! modal || ! modalImage) {
                return;
            }

            const images = thumbnails.length > 0
                ? thumbnails.map(
                    (thumbnail) => thumbnail.dataset.image,
                )
                : [mainImage.currentSrc || mainImage.src];

            let currentIndex = 0;
            let previousBodyOverflow = '';
            let lastFocusedElement = null;
            let touchStartX = null;

            const normalizeIndex = (index) => {
                if (images.length === 0) {
                    return 0;
                }

                return (
                    (index % images.length) + images.length
                ) % images.length;
            };

            const preloadAdjacentImages = () => {
                if (images.length < 2) {
                    return;
                }

                [
                    normalizeIndex(currentIndex - 1),
                    normalizeIndex(currentIndex + 1),
                ].forEach((index) => {
                    const image = new Image();

                    image.src = images[index];
                });
            };

            const showImage = (index) => {
                currentIndex = normalizeIndex(index);

                const imageUrl = images[currentIndex];

                mainImage.src = imageUrl;
                modalImage.src = imageUrl;

                if (currentCounter) {
                    currentCounter.textContent =
                        String(currentIndex + 1);
                }

                thumbnails.forEach(
                    (thumbnail, thumbnailIndex) => {
                        const isActive =
                            thumbnailIndex === currentIndex;

                        thumbnail.setAttribute(
                            'aria-current',
                            isActive ? 'true' : 'false',
                        );

                        thumbnail.classList.toggle(
                            'border-violet-500',
                            isActive,
                        );

                        thumbnail.classList.toggle(
                            'ring-2',
                            isActive,
                        );

                        thumbnail.classList.toggle(
                            'ring-violet-100',
                            isActive,
                        );

                        thumbnail.classList.toggle(
                            'border-slate-200',
                            ! isActive,
                        );
                    },
                );

                preloadAdjacentImages();
            };

            const showPreviousImage = () => {
                showImage(currentIndex - 1);
            };

            const showNextImage = () => {
                showImage(currentIndex + 1);
            };

            const openModal = () => {
                lastFocusedElement = document.activeElement;
                previousBodyOverflow =
                    document.body.style.overflow;

                modal.hidden = false;
                document.body.style.overflow = 'hidden';

                showImage(currentIndex);

                closeButton?.focus();
            };

            const closeModal = () => {
                modal.hidden = true;
                document.body.style.overflow =
                    previousBodyOverflow;

                if (
                    lastFocusedElement
                    instanceof HTMLElement
                ) {
                    lastFocusedElement.focus();
                }
            };

            thumbnails.forEach((thumbnail, index) => {
                thumbnail.addEventListener('click', () => {
                    showImage(index);
                });
            });

            openButton.addEventListener(
                'click',
                openModal,
            );

            closeButton?.addEventListener(
                'click',
                closeModal,
            );

            previousButton?.addEventListener(
                'click',
                showPreviousImage,
            );

            nextButton?.addEventListener(
                'click',
                showNextImage,
            );

            modal.addEventListener('click', (event) => {
                const imageStage = modalImage.parentElement;

                if (
                    event.target === modal
                    || event.target === imageStage
                ) {
                    closeModal();
                }
            });

            modal.addEventListener(
                'touchstart',
                (event) => {
                    touchStartX =
                        event.changedTouches[0]?.clientX
                        ?? null;
                },
                {
                    passive: true,
                },
            );

            modal.addEventListener(
                'touchend',
                (event) => {
                    if (touchStartX === null) {
                        return;
                    }

                    const touchEndX =
                        event.changedTouches[0]?.clientX
                        ?? touchStartX;

                    const distance =
                        touchEndX - touchStartX;

                    touchStartX = null;

                    if (Math.abs(distance) < 50) {
                        return;
                    }

                    if (distance > 0) {
                        showPreviousImage();
                    } else {
                        showNextImage();
                    }
                },
                {
                    passive: true,
                },
            );

            document.addEventListener(
                'keydown',
                (event) => {
                    if (modal.hidden) {
                        return;
                    }

                    if (event.key === 'Escape') {
                        closeModal();
                    }

                    if (event.key === 'ArrowLeft') {
                        showPreviousImage();
                    }

                    if (event.key === 'ArrowRight') {
                        showNextImage();
                    }
                },
            );

            showImage(0);
        });
});