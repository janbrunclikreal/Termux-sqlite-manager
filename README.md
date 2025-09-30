
## 🚀 Použití

1.  Po spuštění aplikace vás uvítá úvodní obrazovka. Začněte vytvořením nové databáze nebo výběrem existující.
2.  Po výběru databáze se v postranním panelu (nebo horní liště na mobilu) zobrazí seznam jejích tabulek.
3.  Kliknutím na tabulku zobrazíte její data. Zde můžete vkládat, upravovat a mazat záznamy.
4.  Pomocí tlačítek v seznamu tabulek nebo v horní liště můžete přistupovat k dalším funkcím, jako je úprava struktury nebo spuštění SQL dotazu.
5.  V sekci "Struktura" tabulky najdete podrobné informace o sloupcích a přehled všech indexů.

## ⚙️ Technické poznámky

-   **Bezpečnost:** Všechny databázové dotazy jsou prováděny pomocí **PDO prepared statements**, což chrání proti SQL injection vstřikům.
-   **Úprava struktury:** Protože SQLite má omezené možnosti pro `ALTER TABLE` (např. nelze přímo smazat sloupec), aplikace pro složitější změny používá mechanismus "rebuild" – vytvoří novou tabulku, zkopíruje data, smaže starou a přejmenuje novou. Celý proces probíhá v transakci pro zajištění bezpečnosti.
-   **Kompatibilita:** Kód je napsán s ohledem na kompatibilitu a měl fungovat na PHP 5.3 a novějších.
-   **UI Framework:** Uživatelské rozhraní je postaveno na **Bootstrapu 5**, který je načítán z CDN, takže není potřeba žádná lokální instalace.

## 📄 Licence

Tento projekt je licencován pod MIT licencí.

---

## Autor

Vytvořeno s láskou k databázím a mobilnímu vývoji.