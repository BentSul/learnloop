import { useState } from 'react';
import { importOnlineQuestions, searchOnlineQuestions } from '../api/adaptive';
import { parseApiError } from '../api/client';
import { useDialog } from './useDialog';

/**
 * Find questions online (QuizAPI) modal/panel (AGENTS.md §15).
 *
 * - Shown only when GET /api/learning-sources reports question_search === true.
 * - Searches GET /api/topics/{topicId}/question-search?tags=...&difficulty=...&limit=10
 * - Displays each result with its options, the correct one(s) marked, and a checkbox
 *   (disabled when already_in_bank is true).
 * - Imports selected items exactly as received via POST /api/topics/{topicId}/question-search/import.
 * - Shows "Questions from QuizAPI.io" as attribution.
 */
export default function LecturerQuizApiSearch({ topicId, topicName, onImported, onClose }) {
    const dialogRef = useDialog(onClose);
    const [tags, setTags] = useState('');
    const [difficulty, setDifficulty] = useState('');
    const [limit, setLimit] = useState(10);

    const [results, setResults] = useState([]);
    const [selectedKeys, setSelectedKeys] = useState({});
    const [searching, setSearching] = useState(false);
    const [hasSearched, setHasSearched] = useState(false);
    const [importing, setImporting] = useState(false);
    const [importReport, setImportReport] = useState(null);
    const [error, setError] = useState(null);

    const itemKey = (item, idx) => String(item.external_id ?? idx);

    const handleSearch = async (e) => {
        e.preventDefault();
        setSearching(true);
        setError(null);
        setImportReport(null);
        setSelectedKeys({});
        try {
            const params = {
                ...(tags.trim() ? { tags: tags.trim() } : {}),
                ...(difficulty ? { difficulty } : {}),
                limit: Number(limit) || 10,
            };
            const res = await searchOnlineQuestions(topicId, params);
            setResults(res?.data ?? (Array.isArray(res) ? res : []));
            setHasSearched(true);
        } catch (err) {
            setError(parseApiError(err).message);
        } finally {
            setSearching(false);
        }
    };

    const toggleSelect = (key) => {
        setSelectedKeys((prev) => ({ ...prev, [key]: !prev[key] }));
    };

    const selectedItems = results.filter(
        (item, idx) => !item.already_in_bank && selectedKeys[itemKey(item, idx)],
    );

    const handleImportSelected = async () => {
        if (selectedItems.length === 0) return;
        setImporting(true);
        setError(null);
        setImportReport(null);
        try {
            const report = await importOnlineQuestions(topicId, selectedItems);
            setImportReport(report);
            setSelectedKeys({});
            onImported?.();
        } catch (err) {
            setError(parseApiError(err).message);
        } finally {
            setImporting(false);
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
            <div
                role="dialog"
                ref={dialogRef}
                tabIndex={-1}
                aria-modal="true"
                aria-labelledby="quizapi-modal-title"
                className="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl"
            >
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 id="quizapi-modal-title" className="text-lg font-semibold text-slate-900">
                            Find questions online: {topicName}
                        </h2>
                        <p className="mt-0.5 text-xs text-slate-600">
                            Questions from QuizAPI.io — preview and import objective questions into
                            this topic’s question bank.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
                    >
                        Close
                    </button>
                </div>

                <form
                    onSubmit={handleSearch}
                    className="mt-4 flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3.5 text-xs"
                >
                    <div className="min-w-[180px] flex-1">
                        <label
                            htmlFor="quizapi_tags"
                            className="mb-1 block font-medium text-slate-700"
                        >
                            Tags (comma-separated, e.g. html,javascript,php)
                        </label>
                        <input
                            id="quizapi_tags"
                            type="text"
                            value={tags}
                            onChange={(e) => setTags(e.target.value)}
                            placeholder="html,css,javascript"
                            className="w-full rounded-md border border-slate-300 bg-white px-2.5 py-1.5"
                        />
                    </div>

                    <div>
                        <label
                            htmlFor="quizapi_difficulty"
                            className="mb-1 block font-medium text-slate-700"
                        >
                            Difficulty
                        </label>
                        <select
                            id="quizapi_difficulty"
                            value={difficulty}
                            onChange={(e) => setDifficulty(e.target.value)}
                            className="rounded-md border border-slate-300 bg-white px-2.5 py-1.5"
                        >
                            <option value="">Any</option>
                            <option value="easy">Easy</option>
                            <option value="medium">Medium</option>
                            <option value="hard">Hard</option>
                        </select>
                    </div>

                    <div>
                        <label
                            htmlFor="quizapi_limit"
                            className="mb-1 block font-medium text-slate-700"
                        >
                            Limit
                        </label>
                        <select
                            id="quizapi_limit"
                            value={limit}
                            onChange={(e) => setLimit(Number(e.target.value))}
                            className="rounded-md border border-slate-300 bg-white px-2.5 py-1.5"
                        >
                            <option value={5}>5</option>
                            <option value={10}>10</option>
                            <option value={15}>15</option>
                            <option value={20}>20</option>
                        </select>
                    </div>

                    <button
                        type="submit"
                        disabled={searching}
                        className="rounded-md bg-indigo-700 px-4 py-1.5 font-medium text-white hover:bg-indigo-800 disabled:opacity-60"
                    >
                        {searching ? 'Searching…' : 'Search QuizAPI'}
                    </button>
                </form>

                {error && (
                    <div
                        role="alert"
                        className="mt-3 rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700"
                    >
                        {error}
                    </div>
                )}

                {importReport && (
                    <div className="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-950">
                        <div className="font-semibold">
                            Imported {importReport.imported?.length ?? 0} question(s); rejected{' '}
                            {importReport.rejected?.length ?? 0}.
                        </div>
                        {Array.isArray(importReport.rejected) &&
                            importReport.rejected.length > 0 && (
                                <ul className="mt-1 space-y-0.5 text-rose-700">
                                    {importReport.rejected.map((r, idx) => (
                                        <li key={idx}>
                                            Row {r.row}: {r.reason}
                                        </li>
                                    ))}
                                </ul>
                            )}
                    </div>
                )}

                {hasSearched && results.length === 0 && !searching && (
                    <p className="mt-4 text-xs text-slate-600">
                        No questions found matching those filters.
                    </p>
                )}

                {results.length > 0 && (
                    <div className="mt-4 space-y-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className="text-xs text-slate-600">
                                Found {results.length} question(s) ·{' '}
                                <strong>Questions from QuizAPI.io</strong>
                            </span>
                            <button
                                type="button"
                                disabled={selectedItems.length === 0 || importing}
                                onClick={handleImportSelected}
                                className="rounded-md bg-emerald-700 px-3.5 py-1.5 text-xs font-medium text-white hover:bg-emerald-800 disabled:opacity-50"
                            >
                                {importing
                                    ? 'Importing…'
                                    : `Import selected (${selectedItems.length})`}
                            </button>
                        </div>

                        <ul className="space-y-3">
                            {results.map((item, idx) => {
                                const key = itemKey(item, idx);
                                const isChecked = Boolean(selectedKeys[key]);
                                const correctIndices = Array.isArray(item.correct)
                                    ? item.correct.map(Number)
                                    : [];

                                return (
                                    <li
                                        key={key}
                                        className="rounded-xl border border-slate-200 bg-white p-4 text-xs shadow-xs"
                                    >
                                        <div className="flex items-start gap-3">
                                            <input
                                                id={`quizapi-check-${key}`}
                                                type="checkbox"
                                                disabled={Boolean(item.already_in_bank)}
                                                checked={isChecked}
                                                onChange={() => toggleSelect(key)}
                                                aria-label={`Select question: ${item.question}`}
                                                className="mt-0.5 h-4 w-4 text-indigo-700"
                                            />
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <label
                                                        htmlFor={`quizapi-check-${key}`}
                                                        className="cursor-pointer text-sm font-medium text-slate-900"
                                                    >
                                                        {item.question}
                                                    </label>
                                                    {item.difficulty && (
                                                        <span className="rounded bg-slate-100 px-2 py-0.5 capitalize text-slate-700">
                                                            {item.difficulty}
                                                        </span>
                                                    )}
                                                    {item.already_in_bank && (
                                                        <span className="rounded bg-amber-100 px-2 py-0.5 font-medium text-amber-900">
                                                            Already in bank
                                                        </span>
                                                    )}
                                                </div>

                                                {Array.isArray(item.options) && (
                                                    <ul className="mt-2.5 grid gap-1.5 sm:grid-cols-2">
                                                        {item.options.map((optText, optIdx) => {
                                                            const isCorrect =
                                                                correctIndices.includes(optIdx);
                                                            return (
                                                                <li
                                                                    key={optIdx}
                                                                    className={`flex items-center justify-between rounded border px-2.5 py-1 ${
                                                                        isCorrect
                                                                            ? 'border-emerald-300 bg-emerald-50 font-medium text-emerald-900'
                                                                            : 'border-slate-200 bg-slate-50 text-slate-700'
                                                                    }`}
                                                                >
                                                                    <span>{optText}</span>
                                                                    {isCorrect && (
                                                                        <span className="ml-2 shrink-0 text-emerald-800">
                                                                            ✓ Correct
                                                                        </span>
                                                                    )}
                                                                </li>
                                                            );
                                                        })}
                                                    </ul>
                                                )}

                                                {item.explanation && (
                                                    <p className="mt-2 text-slate-600">
                                                        <strong>Explanation:</strong>{' '}
                                                        {item.explanation}
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                )}
            </div>
        </div>
    );
}
