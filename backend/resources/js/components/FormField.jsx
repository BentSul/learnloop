export default function FormField({ label, name, type = 'text', value, onChange, errors, autoComplete }) {
    const messages = errors?.[name] ?? [];

    return (
        <div>
            <label htmlFor={name} className="mb-1 block text-sm font-medium text-slate-700">
                {label}
            </label>
            <input
                id={name}
                name={name}
                type={type}
                value={value}
                onChange={onChange}
                autoComplete={autoComplete}
                required
                aria-invalid={messages.length > 0}
                className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
            {messages.map((m) => (
                <p key={m} className="mt-1 text-xs text-red-600">
                    {m}
                </p>
            ))}
        </div>
    );
}
