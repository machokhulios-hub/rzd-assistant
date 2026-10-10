"""Единый стиль документов: правило для загружаемых документов.

К документам из documents/_style/документы.txt при сборке сайта применяются:
  1. исправления из documents/_style/исправления.txt (орфография, пунктуация: «было -> стало»);
  2. проверка орфографии: слова, которых нет в словаре русского языка (pymorphy3) и в
     documents/_style/словарь.txt, выводятся при сборке с вариантами исправления;
  3. типографика: кавычки «ёлочки», тире вместо дефиса между словами, знак №, неразрывные пробелы,
     лишние пробелы убираются;
  4. оформление: текст выровнен по левому и правому краю, гриф («УТВЕРЖДЕНА...») и заголовок
     документа из нескольких строк собираются в один блок.
Исходные файлы не меняются - правило действует на страницу для чтения и на поиск."""
import html
import re
from pathlib import Path

DIR = Path(__file__).parent / "documents" / "_style"
_morph = False      # pymorphy3.MorphAnalyzer или None, если пакета нет; False - ещё не загружали


def _lines(name: str) -> list[str]:
    f = DIR / name
    if not f.exists():
        return []
    return [x.strip() for x in f.read_text("utf-8").splitlines() if x.strip() and not x.lstrip().startswith("#")]


def applies(path: Path) -> bool:
    """Правило действует на документ: в списке его имя, имя без расширения, его папка или «*»."""
    names = set(_lines("документы.txt"))
    return bool(names & {"*", path.name, path.stem, path.parent.name})


def signature() -> bytes:
    """Всё, от чего зависит результат: при изменении правила документы пересобираются."""
    return Path(__file__).read_bytes() + b"".join((DIR / n).read_bytes() for n in ("документы.txt", "исправления.txt", "словарь.txt") if (DIR / n).exists())


def _corrections() -> list[tuple[re.Pattern, str, str]]:
    out = []
    for x in _lines("исправления.txt"):
        if "->" not in x:
            continue
        a, b = (s.strip() for s in x.split("->", 1))
        if a:
            out.append((re.compile(r"(?<![\w-])" + re.escape(a) + r"(?![\w-])"), a, b))
            if a[0].islower():      # то же слово в начале предложения - с большой буквы
                A, B = a[0].upper() + a[1:], b[:1].upper() + b[1:]
                out.append((re.compile(r"(?<![\w-])" + re.escape(A) + r"(?![\w-])"), A, B))
    return out


class Fixer:
    """Правит текст по кускам (абзацы, ячейки, куски между тегами), помня, открыта ли кавычка."""

    def __init__(self):
        self.corr = _corrections()
        self.prev, self.depth = "\n", 0
        self.fixed = []         # (было, стало) - что исправлено

    def __call__(self, t: str) -> str:
        for rx, a, b in self.corr:
            t, n = rx.subn(lambda m: b, t)
            if n:
                self.fixed.append((a, b))
        t = re.sub(r"[ \t\u00a0]{2,}", " ", t)
        t = re.sub(r" +([,.;:!?)»])", r"\1", t)
        t = re.sub(r"([(«]) +", r"\1", t)
        t = re.sub(r"(^|(?<=\s))[-–](?=\s)", "—", t, flags=re.M)        # «далее - ПТЭ» -> «далее — ПТЭ»
        t = re.sub(r" —", "\u00a0—", t)                                 # тире не уходит в начало строки
        t = re.sub(r"(?<![\w№])N\s?(?=\d)", "№\u00a0", t)               # «N 1215/р» -> «№ 1215/р»
        t = re.sub(r"(\d) (г\.|гг\.|ч\.|мин\.|км|м|мм|кг|т|%)(?!\w)", "\\1\u00a0\\2", t)
        t = re.sub(r"(?<!\w)(ОАО|ПАО|АО|ООО) (?=\S)", "\\1\u00a0", t)               # «ОАО «РЖД»» не разрывается
        out = []
        for ch in t:
            if ch == "«":
                self.depth += 1
            elif ch == "»":
                self.depth = max(0, self.depth - 1)
            elif ch in "\"“”„":
                if self.prev.isspace() or self.prev in "([«„-—/":
                    ch = "«" if self.depth == 0 else "„"
                    self.depth += 1
                else:
                    self.depth = max(0, self.depth - 1)
                    ch = "»" if self.depth == 0 else "“"
            out.append(ch)
            self.prev = ch
        return "".join(out)


def _known(word: str, own: set) -> bool:
    w = word.lower().replace("ё", "е")
    return w in own or _morph.word_is_known(w)


def _suggest(word: str, own: set) -> list[str]:
    """Слова словаря, отличающиеся одной буквой (пропущена, лишняя, заменена, переставлена)."""
    w, abc = word.lower(), "абвгдежзийклмнопрстуфхцчшщъыьэюя"
    c = {w[:i] + w[i + 1:] for i in range(len(w))}
    c |= {w[:i] + w[i + 1] + w[i] + w[i + 2:] for i in range(len(w) - 1)}
    c |= {w[:i] + a + w[i + 1:] for i in range(len(w)) for a in abc}
    c |= {w[:i] + a + w[i:] for i in range(len(w) + 1) for a in abc}
    return sorted(x for x in c if len(x) > 2 and _known(x, own))[:3]


def spelling(text: str) -> list[str]:
    """Слова, которых нет в словаре: «слово (может быть: ...)». Без pymorphy3 проверка пропускается."""
    global _morph
    if _morph is False:
        try:
            import pymorphy3
            _morph = pymorphy3.MorphAnalyzer()
        except ImportError:
            _morph = None
            print("  проверка орфографии пропущена: нужен pymorphy3 (pip install -r requirements.txt)")
    if _morph is None:
        return []
    own = {x.lower().replace("ё", "е") for x in _lines("словарь.txt")}
    out, seen = [], set()
    for w in re.findall(r"[А-Яа-яЁё]+(?:-[А-Яа-яЁё]+)*", text):
        if w in seen:
            continue
        seen.add(w)
        if w.lower() in own:
            continue
        for part in w.split("-"):
            if len(part) < 3 or part.isupper() or _known(part, own):    # аббревиатуры (ПТЭ, ТЧД) не проверяем
                continue
            s = _suggest(part, own)
            out.append(w + (f" (может быть: {', '.join(s)})" if s else ""))
            break
    return out


def page_body(body: str, name: str) -> str:
    """HTML страницы для чтения -> HTML единого стиля; найденное при проверке печатается при сборке."""
    fix = Fixer()
    pieces = re.split(r"(<[^>]+>)", body)
    for i, p in enumerate(pieces):
        if p and not p.startswith("<"):
            pieces[i] = html.escape(fix(html.unescape(p)), quote=False)
    lines = "".join(pieces).split("\n")
    out = []
    for x in lines:     # гриф справа и заголовок документа в несколько строк - одним блоком
        prev = out[-1] if out else ""
        if x.startswith('<p class="r">') and prev.startswith('<p class="r">'):
            out[-1] = prev[:-4] + "<br>" + x[len('<p class="r">'):]
        elif x.startswith("<h2>") and prev.startswith("<h2>") and not any(l.startswith(("<p>", "<h3>")) for l in out) \
                and not re.match(r"<h2>(?:[IVXLC]+\.|\d+\.|Глава|ГЛАВА|Раздел|РАЗДЕЛ)\s", x):
            out[-1] = prev[:-5] + "<br>" + x[4:]
        else:
            out.append(x)
    body = "\n".join(out)
    plain = html.unescape(re.sub(r"<[^>]+>", " ", body))
    bad = spelling(plain)
    if fix.fixed or bad:
        print(f"  {name} - единый стиль:")
    for a, b in dict.fromkeys(fix.fixed):
        print(f"    исправлено: {a} -> {b}")
    for w in bad:      # верное слово - в documents/_style/словарь.txt, ошибку - в исправления.txt
        print(f"    проверьте орфографию: {w}")
    return body
