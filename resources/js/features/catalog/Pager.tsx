import { tr, number } from "../../i18n/runtime";
export function Pager({
    page,
    pages,
    setPage,
}: {
    page: number;
    pages: number;
    setPage: (page: number) => void;
}) {
    if (pages < 2) return null;
    return (
        <div className="pager">
            <button
                className="button secondary"
                disabled={page === 1}
                onClick={() => setPage(page - 1)}
            >
                {tr("m1a592f6b2d")}
            </button>
            <span>
                {number(page)} / {number(pages)}
            </span>
            <button
                className="button secondary"
                disabled={page >= pages}
                onClick={() => setPage(page + 1)}
            >
                {tr("m54ee927e96")}
            </button>
        </div>
    );
}
