import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ["piecesCount", "puzzleName", "manufacturer", "puzzleItem", "availability", "withTime"];

    connect() {
        this.populateFilters();
    }

    populateFilters() {
        let piecesCounts = new Set();
        let manufacturers = new Set();

        this.puzzleItemTargets.forEach(puzzle => {
            piecesCounts.add(puzzle.dataset.piecesCount);
            manufacturers.add(puzzle.dataset.manufacturer);
        });

        this.populateSelect(this.piecesCountTarget, Array.from(piecesCounts), "- Dílků -", true);
        this.populateSelect(this.manufacturerTarget, Array.from(manufacturers), "- Výrobce -", false);
    }

    populateSelect(selectElement, options, placeholder, isNumeric) {
        // Add placeholder as the first option
        let placeholderOption = document.createElement('option');
        placeholderOption.value = "";
        placeholderOption.innerHTML = placeholder;
        selectElement.appendChild(placeholderOption);

        // Sort options
        if (isNumeric) {
            options.sort((a, b) => a - b);
        } else {
            options.sort();
        }

        options.forEach(option => {
            let opt = document.createElement('option');
            opt.value = option;
            opt.innerHTML = option;
            selectElement.appendChild(opt);
        });
    }

    filterPuzzles() {
        const piecesCount = this.piecesCountTarget.value;
        const puzzleNameInput = this.normalizeString(this.puzzleNameTarget.value);
        const manufacturer = this.manufacturerTarget.value;
        const onlyAvailable = this.availabilityTarget.checked;
        const onlyWithTime = this.withTimeTarget.checked;
        let anyVisible = false;

        this.puzzleItemTargets.forEach(puzzle => {
            const matchesPiecesCount = piecesCount === "" || puzzle.dataset.piecesCount === piecesCount;
            const puzzleName = this.normalizeString(puzzle.dataset.puzzleName);
            const puzzleAlternativeName = this.normalizeString(puzzle.dataset.puzzleAlternativeName);
            const matchesName = puzzleNameInput === "" || puzzleName.includes(puzzleNameInput) || puzzleAlternativeName.includes(puzzleNameInput);
            const matchesManufacturer = manufacturer === "" || puzzle.dataset.manufacturer === manufacturer;
            const matchesAvailability = !onlyAvailable || puzzle.dataset.available === "1";
            const matchesWithTime = !onlyWithTime || puzzle.dataset.hasTime === "1";
            const isVisible = matchesPiecesCount && matchesName && matchesManufacturer && matchesAvailability && matchesWithTime;

            puzzle.style.display = isVisible ? '' : 'none';
            if (isVisible) {
                anyVisible = true;
            }

            this.updateNoResultsMessage(anyVisible);
        });
    }

    updateNoResultsMessage(anyVisible) {
        const noResultsElement = document.getElementById('filter-no-results');
        if (noResultsElement) {
            if (anyVisible) {
                noResultsElement.classList.add('hidden');
            } else {
                noResultsElement.classList.remove('hidden');
            }
        }
    }

    normalizeString(str) {
        return str.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();
    }
}
