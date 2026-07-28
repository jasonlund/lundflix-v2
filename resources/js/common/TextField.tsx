interface TextFieldProps {
    id: string;
    label: string;
    type: 'text' | 'email' | 'password';
    /** Left unset for display-only fields, so nothing is submitted for them. */
    name?: string;
    value?: string;
    defaultValue?: string;
    autoComplete?: string;
    required?: boolean;
    readOnly?: boolean;
    error?: string;
}

export default function TextField({
    id,
    label,
    type,
    name,
    value,
    defaultValue,
    autoComplete,
    required = false,
    readOnly = false,
    error,
}: TextFieldProps) {
    return (
        <p>
            <label htmlFor={id}>{label}</label>
            <br />
            <input
                id={id}
                name={name}
                type={type}
                value={value}
                defaultValue={defaultValue}
                autoComplete={autoComplete}
                required={required}
                readOnly={readOnly}
            />
            {error && (
                <>
                    <br />
                    <small>{error}</small>
                </>
            )}
        </p>
    );
}
